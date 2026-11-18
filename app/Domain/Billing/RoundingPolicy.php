<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * The only place integer division happens in money and token math, so every rounding
 * decision is explicit, named and tested. Divisors must be positive.
 */
final class RoundingPolicy
{
    /**
     * Divide and round to the nearest integer, ties away from zero ("half up" for the
     * non-negative amounts this app handles). Used for every charge.
     */
    public static function divideHalfUp(int $dividend, int $divisor): int
    {
        self::assertPositiveDivisor($divisor);

        $quotient = intdiv($dividend, $divisor);
        $remainder = abs($dividend % $divisor);

        if ($remainder >= $divisor - $remainder) {
            $quotient += $dividend < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /**
     * Divide and round towards negative infinity. Used for lower token bounds.
     */
    public static function divideFloor(int $dividend, int $divisor): int
    {
        self::assertPositiveDivisor($divisor);

        $quotient = intdiv($dividend, $divisor);

        if ($dividend % $divisor !== 0 && $dividend < 0) {
            $quotient--;
        }

        return $quotient;
    }

    /**
     * Divide and round towards positive infinity. Used for token estimates and upper bounds.
     */
    public static function divideCeil(int $dividend, int $divisor): int
    {
        self::assertPositiveDivisor($divisor);

        $quotient = intdiv($dividend, $divisor);

        if ($dividend % $divisor !== 0 && $dividend > 0) {
            $quotient++;
        }

        return $quotient;
    }

    private static function assertPositiveDivisor(int $divisor): void
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException("Divisor must be positive, got {$divisor}.");
        }
    }
}
