<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JensS\Newsletter\Webhooks\DeliveryEventRecorder;
use JensS\Newsletter\Webhooks\GenericSignature;

/**
 * Provider-neutral endpoint, e.g. for a bounce-mailbox processor when sending
 * through plain SMTP. Body (JSON):
 *   {"id": "evt-1", "type": "bounced|complained|delivered|failed",
 *    "delivery": "<uuid>"?, "message_id": "<Message-ID>"?, "email": "a@b.c"?,
 *    "permanent": true?, "details": "..."?}
 * Header: X-Newsletter-Signature: t=<unix>,v1=<hex hmac-sha256(secret, "<t>.<body>")>
 */
class GenericWebhookController extends Controller
{
    public function __invoke(Request $request, DeliveryEventRecorder $recorder): JsonResponse
    {
        $body = $request->getContent();

        $valid = GenericSignature::verify(
            (string) config('newsletter.webhooks.generic.secret'),
            (string) $request->header('X-Newsletter-Signature', ''),
            $body,
            (int) config('newsletter.webhooks.generic.tolerance_seconds', 300),
        );

        if (! $valid) {
            return new JsonResponse(['status' => 'invalid signature'], 401);
        }

        $event = json_decode($body, true);
        $types = [DeliveryEventRecorder::BOUNCED, DeliveryEventRecorder::COMPLAINED, DeliveryEventRecorder::DELIVERED, DeliveryEventRecorder::FAILED];

        if (! is_array($event) || ! in_array($event['type'] ?? null, $types, true) || empty($event['id'])) {
            return new JsonResponse(['status' => 'invalid payload'], 422);
        }

        if (! $recorder->claimEvent('generic', (string) $event['id'], $event['type'], $event)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        $delivery = $recorder->findDelivery(
            deliveryUuid: $event['delivery'] ?? null,
            messageId: $event['message_id'] ?? null,
        );

        $recorder->record(
            $delivery,
            $event['email'] ?? null,
            $event['type'],
            permanent: array_key_exists('permanent', $event) ? (bool) $event['permanent'] : true,
            details: isset($event['details']) ? (string) $event['details'] : null,
            source: 'generic',
        );

        $recorder->markProcessed('generic', (string) $event['id']);

        return new JsonResponse(['status' => 'ok']);
    }
}
