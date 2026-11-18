<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider could not take the request right now. Retrying later, with the same idempotency key,
 * is safe.
 *
 * $outcomeUnknown says whether the provider might have acted on the request anyway: false for
 * explicit "busy" answers (rate/concurrency limit, overloaded), which create nothing; true for
 * timeouts, network errors and server errors, where we never saw the answer.
 */
final class ProviderUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Image provider unavailable ({$reason}).", 0, $previous);
    }
}
