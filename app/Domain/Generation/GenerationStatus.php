<?php

declare(strict_types=1);

namespace App\Domain\Generation;

/**
 * Lifecycle of a generation. Pending is local only (not yet accepted by Pixelforge); the rest mirror
 * Pixelforge: queued → processing → succeeded | failed. Status only ever moves forward.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function isBefore(self $other): bool
    {
        return $this->stage() < $other->stage();
    }

    private function stage(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
