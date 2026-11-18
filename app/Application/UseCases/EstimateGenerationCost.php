<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Data\PricedRequest;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Billing\PricingSchedule;
use App\Domain\Billing\RoundingPolicy;
use App\Domain\Generation\GenerationRequest;

/**
 * Price a request before it is started, with the formula used when it is started.
 *
 * The estimate depends on the prompt only through its length (text tokens = ceil(characters / N)),
 * and what people see is the range rounded outward to cents. So the answer also says for which
 * prompt lengths that shown price is exactly the same: the form needs no new request while the
 * user keeps typing within it. Submitting always re-prices on the server.
 */
final readonly class EstimateGenerationCost
{
    public function __construct(
        private ImageGenerationProvider $provider,
        private Markup $markup,
    ) {}

    /**
     * @throws ProviderUnavailable|MalformedProviderPayload|ProviderRefused when prices can't be fetched
     */
    public function handle(GenerationRequest $request, int $imageCount = 1): PricedRequest
    {
        $pricing = $this->provider->pricing();
        $perToken = $pricing->charactersPerTextToken;
        $tokens = $pricing->estimatedTextTokens($request);
        $lastToken = RoundingPolicy::divideCeil(GenerationRequest::MAX_PROMPT_CHARACTERS, $perToken);

        $shown = $this->shownPrice($request, $tokens * $perToken, $imageCount, $pricing);

        $from = $tokens;
        while ($from > 1 && $this->shownPrice($request, ($from - 1) * $perToken, $imageCount, $pricing) === $shown) {
            $from--;
        }

        $to = $tokens;
        while ($to < $lastToken && $this->shownPrice($request, min(($to + 1) * $perToken, GenerationRequest::MAX_PROMPT_CHARACTERS), $imageCount, $pricing) === $shown) {
            $to++;
        }

        $estimate = CostEstimate::for($request, $pricing, $this->markup);

        return new PricedRequest(
            $imageCount,
            $estimate->minimumCharge->times($imageCount),
            $estimate->maximumCharge->times($imageCount),
            ($from - 1) * $perToken + 1,
            min($to * $perToken, GenerationRequest::MAX_PROMPT_CHARACTERS),
        );
    }

    /**
     * The price people see for a prompt of $characters characters (all else equal), as comparable text.
     */
    private function shownPrice(GenerationRequest $request, int $characters, int $imageCount, PricingSchedule $pricing): string
    {
        $estimate = CostEstimate::for(
            new GenerationRequest(str_repeat('x', $characters), $request->size, $request->quality, $request->referenceImageUrls),
            $pricing,
            $this->markup,
        );

        return $estimate->minimumCharge->times($imageCount)->floorToCents()->nanos.'-'.$estimate->maximumCharge->times($imageCount)->ceilToCents()->nanos;
    }
}
