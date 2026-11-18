<?php

declare(strict_types=1);

namespace App\Application\Data;

/**
 * The generation a submit request maps to. Not new when the same form submission arrived before
 * (a double click, a retried request), in which case nothing more must be sent to Pixelforge.
 */
final readonly class StartedGeneration
{
    public function __construct(
        public string $generationId,
        public bool $isNew,
    ) {}
}
