<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use DomainException;

/**
 * A provider update names a different provider generation than the one this generation is linked to.
 */
final class ProviderLinkConflict extends DomainException
{
    public static function between(string $generationId, string $linkedProviderId, string $reportedProviderId): self
    {
        return new self("Generation {$generationId} is linked to {$linkedProviderId}, not {$reportedProviderId}.");
    }
}
