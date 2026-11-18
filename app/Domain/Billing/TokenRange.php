<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * An inclusive range of token counts, e.g. the reasoning tokens a quality level may use.
 */
final readonly class TokenRange
{
    public function __construct(public int $minimum, public int $maximum)
    {
        if ($minimum < 0 || $maximum < $minimum) {
            throw new InvalidArgumentException("Invalid token range {$minimum}..{$maximum}.");
        }
    }
}
