<?php

declare(strict_types=1);

namespace App\Application\Data;

enum ProviderEventOutcome: string
{
    /** The event's generation state was applied (or was already known). */
    case Applied = 'applied';

    /** No local generation matches, e.g. it was created by another environment's API key. */
    case UnknownGeneration = 'unknown_generation';

    /** The event carries no generation state (ping, unhandled type). */
    case Ignored = 'ignored';

    /** Signed by Pixelforge but unreadable; retrying would fail the same way. The poller recovers the state. */
    case Malformed = 'malformed';

    /** It names a different provider generation than the local one is linked to; left for investigation. */
    case Conflict = 'conflict';
}
