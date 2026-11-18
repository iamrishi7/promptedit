<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * A form submission id came back with different values than the generation it already started.
 * Returning that generation would show (and charge) something the user didn't ask for this time.
 */
final class ClientRequestReused extends RuntimeException
{
    public function __construct(public readonly string $generationId)
    {
        parent::__construct("Client request already started generation {$generationId} with different values.");
    }
}
