<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Exceptions\ImageUnavailable;

/**
 * Our own long-term copy of generated images (Pixelforge's links expire 24 hours after completion).
 */
interface ImageArchive
{
    /**
     * Copy the image at $sourceUrl into storage. Storing the same generation again overwrites the
     * same file, so a retry never leaves duplicates.
     *
     * @return string the stored path
     *
     * @throws ImageUnavailable
     */
    public function store(string $generationId, int $userId, string $sourceUrl): string;
}
