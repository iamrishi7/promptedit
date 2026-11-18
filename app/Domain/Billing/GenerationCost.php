<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * The final cost of a finished generation: what Pixelforge charged us and what we charge the user.
 */
final readonly class GenerationCost
{
    public function __construct(public Money $providerCost, public Money $charge) {}

    public static function forUsage(TokenUsage $usage, TokenRates $rates, Markup $markup): self
    {
        $providerCost = $rates->costOf($usage);

        return new self($providerCost, $markup->chargeFor($providerCost));
    }

    /**
     * Failed or rejected generations are never billed by Pixelforge, so they cost the user nothing.
     */
    public static function free(): self
    {
        return new self(Money::zero(), Money::zero());
    }
}
