<?php

declare(strict_types=1);

namespace App\Application\Data;

use DateTimeImmutable;

/**
 * A generation whose submission we gave up on without knowing whether Pixelforge received it.
 */
final readonly class UnconfirmedSubmission
{
    public function __construct(
        public string $generationId,
        public DateTimeImmutable $createdAt,
    ) {}
}
