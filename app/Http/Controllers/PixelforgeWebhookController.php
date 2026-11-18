<?php

namespace App\Http\Controllers;

use App\Application\UseCases\ReceiveProviderEvent;
use App\Infrastructure\Pixelforge\PixelforgeWebhookSignature;
use App\Jobs\ProcessProviderEventJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Pixelforge webhooks. Verifies the signature on the raw body, records the event once by
 * its id, and leaves applying it to a queued job, so Pixelforge always gets a fast 2xx.
 */
class PixelforgeWebhookController extends Controller
{
    /** Real events are a few KB; refuse to hash anything much larger. */
    private const int MAX_BODY_BYTES = 65_536;

    public function __invoke(Request $request, PixelforgeWebhookSignature $signature, ReceiveProviderEvent $receiveProviderEvent): Response
    {
        if (! $signature->isConfigured()) {
            Log::critical('Pixelforge webhook received but PIXELFORGE_WEBHOOK_SECRET is not configured.');

            abort(500, 'Webhook signing secret is not configured.');
        }

        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BODY_BYTES) {
            $this->logRejection($request, 'body too large ('.strlen($payload).' bytes)');

            abort(413);
        }

        $rejection = $signature->rejectionReason($payload, $request->header('Pixelforge-Signature'), now()->getTimestamp());

        if ($rejection !== null) {
            $this->logRejection($request, $rejection);

            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! $this->isShortString($event['id'] ?? null) || ! $this->isShortString($event['type'] ?? null)) {
            $this->logRejection($request, 'signed body is not a Pixelforge event');

            abort(400, 'Malformed event.');
        }

        if ($receiveProviderEvent->handle($event['id'], $event['type'], $payload)) {
            try {
                ProcessProviderEventJob::dispatch($event['id']);
            } catch (Throwable $exception) {
                // The event is recorded, so still answer 2xx: a redelivery would be deduplicated
                // anyway. The poller's sweep applies unprocessed events within minutes.
                Log::error('Could not queue a Pixelforge webhook event; the sweep will apply it', [
                    'event_id' => $event['id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->noContent();
    }

    /**
     * A wrong secret would otherwise silently disable webhooks; never log the secret or the body.
     */
    private function logRejection(Request $request, string $reason): void
    {
        Log::warning('Pixelforge webhook rejected', [
            'reason' => $reason,
            'event_id' => $request->header('Pixelforge-Event-Id'),
            'delivery_attempt' => $request->header('Pixelforge-Delivery-Attempt'),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private function isShortString(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 64;
    }
}
