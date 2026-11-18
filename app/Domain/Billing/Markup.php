<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * What users pay on top of the provider cost, as a whole percentage (25 = cost × 1.25).
 */
final readonly class Markup
{
    public function __construct(public int $percent)
    {
        if ($percent < 0) {
            throw new InvalidArgumentException("Markup cannot be negative, got {$percent}%.");
        }
    }

    /**
     * Charge for a provider cost, rounded half up to the nanodollar in a single step.
     */
    public function chargeFor(Money $providerCost): Money
    {
        return $providerCost->times(100 + $this->percent)->dividedByHalfUp(100);
    }
}
