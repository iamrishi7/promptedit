<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Data\ProviderEvent;
use App\Application\Data\ProviderEventOutcome;
use DateTimeImmutable;

/**
 * Durable record of provider webhook events, deduplicated by event id.
 */
interface ProviderEventInbox
{
    /**
     * Record an event unless its id was already recorded. The raw JSON body is stored as received.
     *
     * @return bool whether the event still needs processing (new, or recorded earlier but not yet applied)
     */
    public function record(string $eventId, string $type, string $payloadJson): bool;

    /**
     * Find and lock an event that has not been processed yet, until the surrounding transaction ends.
     */
    public function findUnprocessedForUpdate(string $eventId): ?ProviderEvent;

    public function markProcessed(string $eventId, ProviderEventOutcome $outcome): void;

    /**
     * Events recorded before the cutoff but still unprocessed (e.g. their job was lost or kept failing).
     *
     * @return list<string> event ids, oldest first
     */
    public function unprocessedReceivedBefore(DateTimeImmutable $cutoff, int $limit): array;
}
