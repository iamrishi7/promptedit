<?php

namespace App\Jobs\Middleware;

use App\Infrastructure\ReadModels\PixelforgeWorkload;
use App\Jobs\SubmitGenerationJob;
use Closure;

/**
 * Pixelforge runs at most 3 generations at once per account and answers a 4th with 429. Rather
 * than spend a request (and the shared 60/minute budget) to hear "busy", a submission waits while
 * our records already show the account at capacity. A race between workers still ends in a 429,
 * which the job handles like any busy answer.
 */
final readonly class WaitForPixelforgeCapacity
{
    public const int RECHECK_AFTER_SECONDS = 5;

    public function __construct(private int $maxConcurrent) {}

    public function handle(SubmitGenerationJob $job, Closure $next): mixed
    {
        if (app(PixelforgeWorkload::class)->generationsInFlight() >= $this->maxConcurrent) {
            $job->release(self::RECHECK_AFTER_SECONDS);

            return null;
        }

        return $next($job);
    }
}
