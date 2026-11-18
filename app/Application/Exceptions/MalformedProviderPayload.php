<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider sent data we cannot interpret (missing field, unknown status, wrong type). Retrying
 * the same payload will fail the same way, so callers record it and move on instead of retrying.
 */
final class MalformedProviderPayload extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("Malformed Pixelforge payload: {$reason}", 0, $previous);
    }
}
