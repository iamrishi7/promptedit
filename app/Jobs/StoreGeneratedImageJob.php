<?php

namespace App\Jobs;

use App\Application\Exceptions\ImageUnavailable;
use App\Application\UseCases\StoreGeneratedImage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Copies a succeeded generation's image to our storage. Runs on the low-priority "images" queue
 * (workers take `--queue=default,images`), so slow downloads never hold up submissions or webhooks.
 * Queued by the poller within a minute of
 * success, well inside Pixelforge's 24-hour link lifetime; temporary errors retry with backoff and
 * the next poll queues it again if every try failed.
 */
#[Queue(self::QUEUE)]
#[Tries(4)]
#[Backoff([10, 30, 60])]
class StoreGeneratedImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'images';

    public int $uniqueFor = 600;

    /** The download allows 30 s; stay below the queue's 90 s retry_after. */
    public int $timeout = 60;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(StoreGeneratedImage $storeGeneratedImage): void
    {
        try {
            $storeGeneratedImage->handle($this->generationId);
        } catch (ImageUnavailable $unavailable) {
            if (! $unavailable->permanent) {
                throw $unavailable;
            }

            // Expired or not an image: the page keeps showing Pixelforge's link (or "expired").
            Log::warning('Generated image could not be stored', ['generation_id' => $this->generationId, 'reason' => $unavailable->getMessage()]);
        }
    }
}
