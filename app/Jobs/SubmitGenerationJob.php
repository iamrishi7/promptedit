<?php

namespace App\Jobs;

use App\Application\Exceptions\ProviderUnavailable;
use App\Application\UseCases\AbandonSubmission;
use App\Application\UseCases\SubmitGeneration;
use App\Jobs\Middleware\WaitForPixelforgeCapacity;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * - Temporary provider errors release the job after Pixelforge's Retry-After, for up to 15 minutes.
 * - Every retry reuses the generation id as the idempotency key, so it can never create or bill a
 *   second generation.
 * - Submissions share a rate limit below Pixelforge's 60 requests/minute, so waiting jobs don't
 *   starve each other or the poller.
 * - While Pixelforge already runs 3 of our generations (its concurrency limit), it waits instead
 *   of spending a request on a certain "busy".
 * - Unique per generation, so a duplicate dispatch can't race the original.
 * - When the job finally gives up after Pixelforge answered, the generation fails for free. If no
 *   attempt ever reached Pixelforge (the job waited out its window in a backed-up queue), the
 *   generation stays pending and the poller re-queues it.
 */
#[MaxExceptions(5)]
#[Backoff([5, 15, 30, 60])]
class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string RATE_LIMITER = 'pixelforge-submissions';

    private const int MAX_BACKOFF_SECONDS = 60;

    private const int RETRY_WINDOW_MINUTES = 15;

    public int $uniqueFor = (self::RETRY_WINDOW_MINUTES + 5) * 60;

    /** Well above the 15 s HTTP timeout, below the queue's 90 s retry_after. */
    public int $timeout = 45;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WaitForPixelforgeCapacity(config()->integer('services.pixelforge.max_concurrent_generations')),
            new RateLimited(self::RATE_LIMITER),
        ];
    }

    public function handle(SubmitGeneration $submitGeneration): void
    {
        try {
            $submitGeneration->handle($this->generationId);
        } catch (ProviderUnavailable $unavailable) {
            // Never sooner than Pixelforge asks, but growing with each try: a generation Pixelforge keeps
            // refusing ("Retry-After: 2" every time) would otherwise retry every 2 s for 15 minutes and
            // use up the submission budget every other user shares.
            $this->release(max($unavailable->retryAfterSeconds ?? 0, $this->exponentialBackoffSeconds()));
        }
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(minutes: self::RETRY_WINDOW_MINUTES);
    }

    public function failed(?Throwable $exception): void
    {
        app(AbandonSubmission::class)->handle($this->generationId, onlyIfAttempted: true);
    }

    private function exponentialBackoffSeconds(): int
    {
        return min(self::MAX_BACKOFF_SECONDS, 2 ** min($this->attempts(), 6));
    }
}
