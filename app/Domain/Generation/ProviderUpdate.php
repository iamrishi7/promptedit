<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Billing\TokenRates;
use App\Domain\Billing\TokenUsage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pixelforge's view of a generation at one moment, from a create response, a webhook or a poll.
 */
final readonly class ProviderUpdate
{
    public function __construct(
        public string $providerId,
        public GenerationStatus $status,
        public ?string $generationId = null,
        public ?TokenUsage $usage = null,
        public ?TokenRates $rates = null,
        public ?string $imageUrl = null,
        public ?DateTimeImmutable $imageUrlExpiresAt = null,
        public ?GenerationFailure $failure = null,
        public ?DateTimeImmutable $completedAt = null,
    ) {
        if ($status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('Pixelforge never reports a pending generation.');
        }

        if ($status === GenerationStatus::Succeeded && ($usage === null || $rates === null || $completedAt === null)) {
            throw new InvalidArgumentException("Succeeded generation {$providerId} must report usage, rates and completion time.");
        }

        if ($status === GenerationStatus::Failed && $failure === null) {
            throw new InvalidArgumentException("Failed generation {$providerId} must report an error.");
        }
    }
}
