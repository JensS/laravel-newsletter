<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JensS\Newsletter\Webhooks\DeliveryEventRecorder;
use JensS\Newsletter\Webhooks\SvixSignature;

/**
 * Resend delivery events (signed with Svix). Configure the endpoint in the
 * Resend dashboard for: email.sent, email.delivered, email.bounced,
 * email.complained, email.failed, email.suppressed.
 */
class ResendWebhookController extends Controller
{
    public function __invoke(Request $request, DeliveryEventRecorder $recorder): JsonResponse
    {
        $body = $request->getContent();
        $id = (string) $request->header('svix-id', $request->header('webhook-id', ''));
        $timestamp = (string) $request->header('svix-timestamp', $request->header('webhook-timestamp', ''));
        $signature = (string) $request->header('svix-signature', $request->header('webhook-signature', ''));

        $valid = SvixSignature::verify(
            (string) config('newsletter.webhooks.resend.secret'),
            $id,
            $timestamp,
            $signature,
            $body,
            (int) config('newsletter.webhooks.resend.tolerance_seconds', 300),
        );

        if (! $valid) {
            return new JsonResponse(['status' => 'invalid signature'], 401);
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! isset($event['type'])) {
            return new JsonResponse(['status' => 'ignored'], 202);
        }

        if (! $recorder->claimEvent('resend', $id, (string) $event['type'], $event)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        $data = (array) ($event['data'] ?? []);
        $tags = $this->tags($data['tags'] ?? []);
        $delivery = $recorder->findDelivery(
            providerMessageId: $data['email_id'] ?? null,
            deliveryUuid: $tags['newsletter_delivery'] ?? null,
        );
        $email = is_array($data['to'] ?? null) ? ($data['to'][0] ?? null) : ($data['to'] ?? null);
        $email = is_string($email) && preg_match('/<([^>]+)>/', $email, $m) ? $m[1] : $email;

        match ($event['type']) {
            'email.sent' => $recorder->record($delivery, null, DeliveryEventRecorder::ACCEPTED, source: 'resend'),
            'email.delivered' => $recorder->record($delivery, null, DeliveryEventRecorder::DELIVERED, source: 'resend'),
            'email.bounced' => $recorder->record(
                $delivery,
                $email,
                DeliveryEventRecorder::BOUNCED,
                permanent: strcasecmp((string) ($data['bounce']['type'] ?? 'Permanent'), 'Permanent') === 0,
                details: trim(($data['bounce']['subType'] ?? '').' '.($data['bounce']['message'] ?? '')) ?: null,
                source: 'resend',
            ),
            'email.complained' => $recorder->record($delivery, $email, DeliveryEventRecorder::COMPLAINED, source: 'resend'),
            'email.failed' => $recorder->record($delivery, null, DeliveryEventRecorder::FAILED, details: $data['failed']['reason'] ?? null, source: 'resend'),
            'email.suppressed' => $recorder->record($delivery, $email, DeliveryEventRecorder::SUPPRESSED, source: 'resend'),
            default => null,
        };

        $recorder->markProcessed('resend', $id);

        return new JsonResponse(['status' => 'ok']);
    }

    /** @return array<string, string> */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        // Resend sends tags as an object {name: value}; accept a list of {name, value} too.
        if (array_is_list($tags)) {
            $out = [];
            foreach ($tags as $tag) {
                if (isset($tag['name'], $tag['value'])) {
                    $out[$tag['name']] = (string) $tag['value'];
                }
            }

            return $out;
        }

        return array_map('strval', $tags);
    }
}
