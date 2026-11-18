<?php

declare(strict_types=1);

namespace App\Application\Data;

use App\Domain\Generation\ProviderUpdate;

/**
 * A recorded provider webhook event, ready to apply. $update is null for events that carry no
 * generation state (e.g. a dashboard ping or an event type we don't handle).
 */
final readonly class ProviderEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public ?ProviderUpdate $update,
    ) {}
}
