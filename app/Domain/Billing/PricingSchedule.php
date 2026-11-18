<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use InvalidArgumentException;

/**
 * Pixelforge's published rates and token rules, used to estimate a generation's cost before it runs.
 *
 * Input image and output tokens are known exactly up front. Text tokens are only approximately
 * ceil(characters / 4), within ±15%, and reasoning tokens vary within a range per quality, so an
 * estimate is always a minimum..maximum range.
 */
final readonly class PricingSchedule
{
    private const int BASIS_POINTS = 10_000;

    /**
     * @param  array<string, array<string, int>>  $outputTokens  output tokens keyed by quality value, then size value
     * @param  array<string, TokenRange>  $reasoningTokens  reasoning token range keyed by quality value
     */
    public function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {
        if ($charactersPerTextToken <= 0) {
            throw new InvalidArgumentException('Characters per text token must be positive.');
        }

        if ($textTokenVarianceBasisPoints < 0 || $textTokenVarianceBasisPoints > self::BASIS_POINTS) {
            throw new InvalidArgumentException('Text token variance must be between 0 and 10000 basis points.');
        }

        if ($tokensPerReferenceImage < 0) {
            throw new InvalidArgumentException('Tokens per reference image cannot be negative.');
        }

        foreach (Quality::cases() as $quality) {
            if (! isset($reasoningTokens[$quality->value])) {
                throw new InvalidArgumentException("Missing reasoning token range for {$quality->value} quality.");
            }

            foreach (ImageSize::cases() as $size) {
                if (($outputTokens[$quality->value][$size->value] ?? -1) < 0) {
                    throw new InvalidArgumentException("Missing output tokens for {$quality->value} {$size->value}.");
                }
            }
        }
    }

    public function outputTokensFor(Quality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    public function reasoningTokensFor(Quality $quality): TokenRange
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * Pixelforge's documented estimate: ceil(prompt characters / characters per token).
     */
    public function estimatedTextTokens(GenerationRequest $request): int
    {
        return RoundingPolicy::divideCeil($request->promptCharacterCount(), $this->charactersPerTextToken);
    }

    /**
     * The real text token count lies within ±variance of the estimate. Bounds are widened to whole
     * tokens (floor for the minimum, ceil for the maximum) so the range always contains the real count.
     */
    public function textTokenRange(GenerationRequest $request): TokenRange
    {
        $estimate = $this->estimatedTextTokens($request);

        return new TokenRange(
            RoundingPolicy::divideFloor($estimate * (self::BASIS_POINTS - $this->textTokenVarianceBasisPoints), self::BASIS_POINTS),
            RoundingPolicy::divideCeil($estimate * (self::BASIS_POINTS + $this->textTokenVarianceBasisPoints), self::BASIS_POINTS),
        );
    }

    public function minimumUsageFor(GenerationRequest $request): TokenUsage
    {
        return new TokenUsage(
            inputTextTokens: $this->textTokenRange($request)->minimum,
            inputImageTokens: $this->imageTokensFor($request),
            outputTokens: $this->outputTokensFor($request->quality, $request->size),
            reasoningTokens: $this->reasoningTokensFor($request->quality)->minimum,
        );
    }

    public function maximumUsageFor(GenerationRequest $request): TokenUsage
    {
        return new TokenUsage(
            inputTextTokens: $this->textTokenRange($request)->maximum,
            inputImageTokens: $this->imageTokensFor($request),
            outputTokens: $this->outputTokensFor($request->quality, $request->size),
            reasoningTokens: $this->reasoningTokensFor($request->quality)->maximum,
        );
    }

    private function imageTokensFor(GenerationRequest $request): int
    {
        return $request->referenceImageCount() * $this->tokensPerReferenceImage;
    }
}
