<?php

declare(strict_types=1);

namespace App\Infrastructure\ReadModels;

use App\Models\Generation as GenerationRecord;
use Carbon\CarbonImmutable;

/**
 * How much work Pixelforge is doing for this account right now, as far as our records show.
 */
final class PixelforgeWorkload
{
    /**
     * A generation stuck "in flight" in our records (its webhook and polls lost) must not block new
     * submissions forever, so only recently active ones count. Real generations take 5–90 seconds.
     */
    private const int ACTIVE_WITHIN_MINUTES = 5;

    public function generationsInFlight(): int
    {
        return GenerationRecord::query()
            ->whereRaw("status in ('queued', 'processing')") // literal list so Postgres can use the partial index
            ->where('updated_at', '>', CarbonImmutable::now('UTC')->subMinutes(self::ACTIVE_WITHIN_MINUTES))
            ->count();
    }
}
