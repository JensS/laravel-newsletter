<?php

namespace JensS\Newsletter\Tests\Feature;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Models\Suppression;
use JensS\Newsletter\Tests\TestCase;
use JensS\Newsletter\Webhooks\GenericSignature;
use JensS\Newsletter\Webhooks\SvixSignature;

class WebhookTest extends TestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_'.base64_encode('resend-test-secret-0123456789');
        config(['newsletter.webhooks.resend.secret' => $this->secret, 'newsletter.webhooks.generic.secret' => 'generic-secret']);
    }

    private function sentDelivery(string $email = 'w@example.test'): Delivery
    {
        Newsletter::subscribe('newsletter', $email, ['confirmed' => true]);
        $campaign = $this->campaign();
        Newsletter::send($campaign);
        $this->work();

        return $campaign->deliveries()->sole();
    }

    private function resend(array $event, string $id = 'msg_1', ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event);
        $ts = (string) time();

        return $this->call('POST', '/newsletter/webhooks/resend', [], [], [], [
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $ts,
            'HTTP_SVIX_SIGNATURE' => $signature ?? SvixSignature::sign($this->secret, $id, $ts, $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_invalid_signatures_are_rejected(): void
    {
        $delivery = $this->sentDelivery();

        $this->resend(['type' => 'email.delivered', 'data' => ['email_id' => $delivery->provider_message_id]], signature: 'v1,bogus')
            ->assertUnauthorized();

        $this->assertSame(DeliveryStatus::Accepted, $delivery->fresh()->status);
    }

    public function test_delivered_events_confirm_delivery(): void
    {
        $delivery = $this->sentDelivery();

        $this->resend(['type' => 'email.delivered', 'data' => ['email_id' => $delivery->provider_message_id, 'to' => [$delivery->email]]])
            ->assertOk();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_permanent_bounces_suppress_the_address(): void
    {
        $delivery = $this->sentDelivery('hard@example.test');

        $this->resend([
            'type' => 'email.bounced',
            'data' => ['email_id' => $delivery->provider_message_id, 'to' => ['hard@example.test'], 'bounce' => ['type' => 'Permanent', 'subType' => 'General', 'message' => 'mailbox unknown']],
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Bounced, $delivery->fresh()->status);
        $this->assertTrue(Suppression::isSuppressed('hard@example.test'));
        $this->assertSame('bounce', Suppression::query()->sole()->reason);

        // Late "delivered" events never downgrade a bounce.
        $this->resend(['type' => 'email.delivered', 'data' => ['email_id' => $delivery->provider_message_id]], id: 'msg_2')->assertOk();
        $this->assertSame(DeliveryStatus::Bounced, $delivery->fresh()->status);
    }

    public function test_transient_bounces_only_suppress_after_the_threshold(): void
    {
        config(['newsletter.suppression.soft_bounce_threshold' => 1]);
        $delivery = $this->sentDelivery('soft@example.test');

        $this->resend([
            'type' => 'email.bounced',
            'data' => ['email_id' => $delivery->provider_message_id, 'to' => ['soft@example.test'], 'bounce' => ['type' => 'Transient']],
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Accepted, $delivery->status);
        $this->assertSame('transient', $delivery->bounce_type);
        $this->assertTrue(Suppression::isSuppressed('soft@example.test'));
    }

    public function test_complaints_suppress_and_are_matched_by_tag(): void
    {
        $delivery = $this->sentDelivery('spam@example.test');

        $this->resend([
            'type' => 'email.complained',
            'data' => ['email_id' => 'unknown-id', 'to' => ['Spam Person <spam@example.test>'], 'tags' => ['newsletter_delivery' => $delivery->uuid]],
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Complained, $delivery->fresh()->status);
        $this->assertSame('complaint', Suppression::query()->sole()->reason);
    }

    public function test_replayed_events_are_ignored(): void
    {
        $delivery = $this->sentDelivery();
        $event = ['type' => 'email.delivered', 'data' => ['email_id' => $delivery->provider_message_id]];

        $this->resend($event, id: 'msg_same')->assertJson(['status' => 'ok']);
        $this->resend($event, id: 'msg_same')->assertJson(['status' => 'duplicate']);
    }

    public function test_generic_webhook_for_smtp_bounce_processing(): void
    {
        $delivery = $this->sentDelivery('smtp@example.test');
        $body = json_encode(['id' => 'dsn-1', 'type' => 'bounced', 'message_id' => '<'.$delivery->message_id.'>', 'permanent' => true, 'details' => '5.1.1']);

        $this->call('POST', '/newsletter/webhooks/generic', [], [], [], [
            'HTTP_X_NEWSLETTER_SIGNATURE' => GenericSignature::sign('generic-secret', $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(DeliveryStatus::Bounced, $delivery->fresh()->status);
        $this->assertTrue(Suppression::isSuppressed('smtp@example.test'));

        $this->call('POST', '/newsletter/webhooks/generic', [], [], [], [
            'HTTP_X_NEWSLETTER_SIGNATURE' => GenericSignature::sign('wrong', $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertUnauthorized();
    }

    public function test_complaint_for_unknown_message_still_suppresses_by_email(): void
    {
        $body = json_encode(['id' => 'fbl-1', 'type' => 'complained', 'email' => 'Someone@Example.test']);

        $this->call('POST', '/newsletter/webhooks/generic', [], [], [], [
            'HTTP_X_NEWSLETTER_SIGNATURE' => GenericSignature::sign('generic-secret', $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertTrue(Suppression::isSuppressed('someone@example.test'));
    }
}
