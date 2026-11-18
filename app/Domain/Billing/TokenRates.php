<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Pixelforge prices, held as an exact amount per single token for each token type.
 *
 * Pixelforge publishes US dollars per one million tokens (e.g. "12.50"). A rate with at most three
 * decimals is a whole number of nanodollars per token, so costs are exact sums with no division.
 * A rate needing more precision is rejected rather than rounded, because Pixelforge never rounds costs.
 *
 * A generation is billed at the rates it was created with, so actual cost always uses the rates
 * returned on that generation, never the live pricing endpoint.
 */
final readonly class TokenRates
{
    private const int TOKENS_PER_PUBLISHED_RATE = 1_000_000;

    private function __construct(
        public Money $inputTextPerToken,
        public Money $inputImagePerToken,
        public Money $outputPerToken,
        public Money $reasoningPerToken,
    ) {}

    /**
     * Build from Pixelforge's exact per-1M-token decimal strings, e.g. "5.00" and "12.50".
     */
    public static function fromDecimalStrings(string $inputText, string $inputImage, string $output, string $reasoning): self
    {
        return new self(
            self::perToken($inputText),
            self::perToken($inputImage),
            self::perToken($output),
            self::perToken($reasoning),
        );
    }

    /**
     * Exact cost of the usage: the sum of tokens × per-token rate over every token type.
     */
    public function costOf(TokenUsage $usage): Money
    {
        return $this->inputTextPerToken->times($usage->inputTextTokens)
            ->plus($this->inputImagePerToken->times($usage->inputImageTokens))
            ->plus($this->outputPerToken->times($usage->outputTokens))
            ->plus($this->reasoningPerToken->times($usage->reasoningTokens));
    }

    private static function perToken(string $dollarsPerMillionTokens): Money
    {
        $perMillion = Money::fromDecimalString($dollarsPerMillionTokens);

        if ($perMillion->nanos % self::TOKENS_PER_PUBLISHED_RATE !== 0) {
            throw new InvalidArgumentException("Rate \"{$dollarsPerMillionTokens}\" per 1M tokens is not a whole number of nanodollars per token; refusing to round it.");
        }

        return Money::fromNanos(RoundingPolicy::divideHalfUp($perMillion->nanos, self::TOKENS_PER_PUBLISHED_RATE));
    }
}
