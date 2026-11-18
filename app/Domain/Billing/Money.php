<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;
use OverflowException;

/**
 * An exact, non-negative amount of US dollars held as integer nanodollars (1 USD = 1_000_000_000).
 *
 * Pixelforge reports costs with exactly nine decimal places, so one nanodollar is the provider's
 * resolution: every provider amount is represented without loss and no floats are ever involved.
 */
final readonly class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private const int DECIMAL_PLACES = 9;

    private const int NANOS_PER_CENT = 10_000_000;

    private function __construct(public int $nanos)
    {
        if ($nanos < 0) {
            throw new InvalidArgumentException("Money cannot be negative, got {$nanos} nanodollars.");
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    /**
     * Parse an exact decimal dollar string such as "12.50" or "0.169080000" without floats.
     */
    public static function fromDecimalString(string $dollars): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/D', $dollars, $matches) !== 1) {
            throw new InvalidArgumentException("Expected a non-negative dollar amount with at most 9 decimals, got \"{$dollars}\".");
        }

        $digits = ltrim($matches[1].str_pad($matches[2] ?? '', self::DECIMAL_PLACES, '0'), '0');

        if (strlen($digits) > 18) {
            throw new OverflowException("Dollar amount \"{$dollars}\" is too large.");
        }

        return new self((int) $digits);
    }

    public function plus(self $other): self
    {
        if ($other->nanos > PHP_INT_MAX - $this->nanos) {
            throw new OverflowException('Money addition overflowed.');
        }

        return new self($this->nanos + $other->nanos);
    }

    public function times(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException("Cannot multiply money by a negative factor ({$factor}).");
        }

        if ($factor !== 0 && $this->nanos > intdiv(PHP_INT_MAX, $factor)) {
            throw new OverflowException('Money multiplication overflowed.');
        }

        return new self($this->nanos * $factor);
    }

    public function dividedByHalfUp(int $divisor): self
    {
        return new self(RoundingPolicy::divideHalfUp($this->nanos, $divisor));
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    /**
     * Rounded down to whole cents, for the low end of a displayed price range.
     */
    public function floorToCents(): self
    {
        return self::fromNanos(RoundingPolicy::divideFloor($this->nanos, self::NANOS_PER_CENT))->times(self::NANOS_PER_CENT);
    }

    /**
     * Rounded up to whole cents, for the high end of a displayed price range, so the range shown
     * always contains the exact amount.
     */
    public function ceilToCents(): self
    {
        return self::fromNanos(RoundingPolicy::divideCeil($this->nanos, self::NANOS_PER_CENT))->times(self::NANOS_PER_CENT);
    }

    /**
     * The exact amount for people: "$0.3818875", "$0.35", "$0.00". Trailing zeros are trimmed down
     * to cents, never rounded, so what is shown is what is recorded.
     */
    public function toDisplayString(): string
    {
        [$whole, $fraction] = explode('.', $this->toDecimalString());

        return '$'.$whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }

    /**
     * Exact dollar amount with nine decimals, the same format Pixelforge uses for cost_usd.
     */
    public function toDecimalString(): string
    {
        $digits = str_pad((string) $this->nanos, self::DECIMAL_PLACES + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -self::DECIMAL_PLACES).'.'.substr($digits, -self::DECIMAL_PLACES);
    }
}
