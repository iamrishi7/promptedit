<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Generation\GenerationRequest;

/**
 * The cost range of a generation before it runs: the provider cost and the user charge if it uses
 * the fewest and the most tokens Pixelforge's rules allow. The actual cost always falls inside.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenUsage $minimumUsage,
        public TokenUsage $maximumUsage,
        public Money $minimumProviderCost,
        public Money $maximumProviderCost,
        public Money $minimumCharge,
        public Money $maximumCharge,
    ) {}

    public static function for(GenerationRequest $request, PricingSchedule $pricing, Markup $markup): self
    {
        $minimumUsage = $pricing->minimumUsageFor($request);
        $maximumUsage = $pricing->maximumUsageFor($request);
        $minimumProviderCost = $pricing->rates->costOf($minimumUsage);
        $maximumProviderCost = $pricing->rates->costOf($maximumUsage);

        return new self(
            $minimumUsage,
            $maximumUsage,
            $minimumProviderCost,
            $maximumProviderCost,
            $markup->chargeFor($minimumProviderCost),
            $markup->chargeFor($maximumProviderCost),
        );
    }
}
