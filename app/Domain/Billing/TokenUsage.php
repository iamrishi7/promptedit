<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Token counts for one generation, per billable token type.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {
        foreach (get_object_vars($this) as $type => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException("Token count {$type} cannot be negative, got {$count}.");
            }
        }
    }

    public function totalTokens(): int
    {
        return $this->inputTextTokens + $this->inputImageTokens + $this->outputTokens + $this->reasoningTokens;
    }
}
