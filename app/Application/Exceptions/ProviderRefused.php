<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use App\Domain\Generation\GenerationFailure;
use RuntimeException;

/**
 * The provider definitively refused the request (content policy, daily quota, invalid request).
 * Nothing was created or billed and retrying the same request will not help.
 */
final class ProviderRefused extends RuntimeException
{
    public function __construct(public readonly GenerationFailure $failure)
    {
        parent::__construct("Image provider refused the request ({$failure->code}): {$failure->message}");
    }
}
