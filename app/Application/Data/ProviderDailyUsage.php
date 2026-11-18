<?php

declare(strict_types=1);

namespace App\Application\Data;

use App\Domain\Billing\Money;

/**
 * What Pixelforge billed this API key on one UTC day: the source of truth for provider costs.
 */
final readonly class ProviderDailyUsage
{
    public function __construct(
        /** YYYY-MM-DD, UTC */
        public string $date,
        public int $generations,
        public Money $cost,
    ) {}
}
