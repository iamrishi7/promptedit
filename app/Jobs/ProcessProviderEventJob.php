<?php

namespace App\Jobs;

use App\Application\Data\ProviderEventOutcome;
use App\Application\UseCases\ProcessProviderEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one recorded Pixelforge webhook event.
 *
 * Deliberately not unique: exactly-once comes from the event row lock and processed_at (a second
 * job is a cheap no-op), while a unique lock could silently drop a re-queue after a job was lost.
 * If every attempt fails, the event stays unprocessed for the sweeper, and the poller still brings
 * the generation up to date.
 */
#[Tries(5)]
#[Backoff([5, 30, 120, 300])]
class ProcessProviderEventJob implements ShouldQueue
{
    use Queueable;

    /** Applying an event is a few queries; anything near the queue's 90 s retry_after is stuck. */
    public int $timeout = 30;

    public function __construct(public string $eventId) {}

    public function handle(ProcessProviderEvent $processProviderEvent): void
    {
        $outcome = $processProviderEvent->handle($this->eventId);

        if ($outcome !== null && $outcome !== ProviderEventOutcome::Applied && $outcome !== ProviderEventOutcome::Ignored) {
            Log::warning('Pixelforge webhook event was not applied', [
                'event_id' => $this->eventId,
                'outcome' => $outcome->value,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Pixelforge webhook event could not be processed; left for the sweeper', [
            'event_id' => $this->eventId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
