<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A generated image could not be fetched for storage. $permanent when retrying cannot help (the
 * link expired or was refused); otherwise a later attempt may succeed.
 */
final class ImageUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
