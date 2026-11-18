<?php

namespace App\Console\Commands;

use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ProviderEventInbox;
use App\Application\UseCases\AbandonSubmission;
use App\Application\UseCases\ConfirmUnconfirmedSubmission;
use App\Application\UseCases\ProcessProviderEvent;
use App\Application\UseCases\RefreshGeneration;
use App\Infrastructure\Pixelforge\PixelforgeImageGenerationProvider;
use App\Jobs\StoreGeneratedImageJob;
use App\Jobs\SubmitGenerationJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Safety net for everything webhooks and queued jobs can miss. Runs every minute:
 *
 * 1. Apply webhook events that were recorded but never processed.
 * 2. Re-queue submissions whose job was lost; abandon ones stuck far too long (40 minutes).
 * 3. Refresh in-flight generations that have heard nothing for a while (missed webhooks).
 * 4. Resolve unconfirmed submissions by looking them up in Pixelforge's records.
 * 5. Queue copies of succeeded images not stored yet (Pixelforge's links expire after 24 hours).
 *
 * Pixelforge calls share a per-minute budget kept below the account limit, leaving room for submissions.
 * On a "busy" answer the run stops calling Pixelforge and tries again next minute.
 */
#[Signature('pixelforge:poll')]
#[Description('Fallback for missed webhooks and lost jobs: refresh, reconcile and sweep Pixelforge generations')]
class PollPixelforge extends Command
{
    public const string RATE_LIMITER = 'pixelforge-polls';

    private const int BATCH_SIZE = 50;

    private const int CHECK_IN_FLIGHT_AFTER_SECONDS = 45;

    private const int CHECK_UNCONFIRMED_EVERY_SECONDS = 120;

    private const int REQUEUE_PENDING_AFTER_MINUTES = 2;

    /**
     * Abandoning is safe even while a job is still trying: each attempt is recorded under the row
     * lock before it is sent, so an abandoned generation is never sent, and one being sent at that
     * moment is abandoned as provisional (billed if Pixelforge did create it).
     */
    private const int ABANDON_PENDING_AFTER_MINUTES = 40;

    private const int SWEEP_EVENTS_AFTER_MINUTES = 10;

    /**
     * Stop starting Pixelforge calls this long into a run, so a slow Pixelforge (each call may take
     * up to its 15 s timeout) can't stretch a run into the next minute's.
     */
    private const int CALL_DEADLINE_SECONDS = 40;

    private bool $providerBusy = false;

    private float $callDeadline = 0.0;

    public function handle(
        GenerationRepository $generations,
        ProviderEventInbox $inbox,
        ProcessProviderEvent $processProviderEvent,
        AbandonSubmission $abandonSubmission,
        RefreshGeneration $refreshGeneration,
        ConfirmUnconfirmedSubmission $confirmUnconfirmedSubmission,
    ): int {
        $now = CarbonImmutable::now('UTC');
        $this->callDeadline = microtime(true) + self::CALL_DEADLINE_SECONDS;

        foreach ($inbox->unprocessedReceivedBefore($now->subMinutes(self::SWEEP_EVENTS_AFTER_MINUTES), self::BATCH_SIZE) as $eventId) {
            $this->attempt("process event {$eventId}", fn () => $processProviderEvent->handle($eventId));
        }

        foreach ($generations->pendingCreatedBefore($now->subMinutes(self::ABANDON_PENDING_AFTER_MINUTES), self::BATCH_SIZE) as $generationId) {
            $this->attempt("abandon {$generationId}", fn () => $abandonSubmission->handle($generationId));
        }

        foreach ($generations->pendingCreatedBefore($now->subMinutes(self::REQUEUE_PENDING_AFTER_MINUTES), self::BATCH_SIZE) as $generationId) {
            // Unique per generation: a no-op while the original job is still queued or retrying.
            SubmitGenerationJob::dispatch($generationId);
        }

        foreach ($generations->inFlightNotCheckedSince($now->subSeconds(self::CHECK_IN_FLIGHT_AFTER_SECONDS), self::BATCH_SIZE) as $generationId) {
            if (! $this->spendBudget(1)) {
                break;
            }

            $this->attempt("refresh {$generationId}", fn () => $refreshGeneration->handle($generationId, $now));
        }

        foreach ($generations->unconfirmedNotCheckedSince($now->subSeconds(self::CHECK_UNCONFIRMED_EVERY_SECONDS), self::BATCH_SIZE) as $submission) {
            if (! $this->spendBudget(PixelforgeImageGenerationProvider::LOOKUP_MAX_PAGES)) {
                break;
            }

            $this->attempt("confirm {$submission->generationId}", fn () => $confirmUnconfirmedSubmission->handle($submission, $now));
        }

        // Image downloads don't count against the API budget. Unique per generation, so a copy
        // still queued or retrying is not queued twice.
        foreach ($generations->succeededWithoutStoredImage($now, self::BATCH_SIZE) as $generationId) {
            StoreGeneratedImageJob::dispatch($generationId);
        }

        return self::SUCCESS;
    }

    /**
     * Run one item; a failure affects only that item, except "busy", which stops further Pixelforge calls.
     */
    private function attempt(string $what, callable $work): void
    {
        try {
            $work();
        } catch (ProviderUnavailable $unavailable) {
            if ($unavailable->reason === PixelforgeImageGenerationProvider::LOOKUP_INCOMPLETE) {
                return; // Not busy: the lookup just could not reach back far enough this time.
            }

            $this->providerBusy = true;
            Log::info('Pixelforge busy during poll; stopping until the next run', ['item' => $what, 'reason' => $unavailable->reason]);
        } catch (Throwable $exception) {
            Log::error('Pixelforge poll item failed', ['item' => $what, 'error' => $exception->getMessage()]);
        }
    }

    private function spendBudget(int $requests): bool
    {
        if ($this->providerBusy || microtime(true) >= $this->callDeadline) {
            return false;
        }

        $perMinute = config()->integer('services.pixelforge.poll_requests_per_minute');

        if (RateLimiter::remaining(self::RATE_LIMITER, $perMinute) < $requests) {
            return false;
        }

        RateLimiter::increment(self::RATE_LIMITER, 60, $requests);

        return true;
    }
}
