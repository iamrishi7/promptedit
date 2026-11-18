<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Data\ProviderEventOutcome;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Ports\ProviderEventInbox;
use App\Application\Ports\TransactionRunner;
use App\Domain\Generation\ProviderLinkConflict;

/**
 * Apply a recorded webhook event exactly once. Locking the event, applying it and marking it
 * processed happen in one transaction: a crash in between leaves the event unprocessed so it is
 * retried, and a second run finds it already processed and does nothing.
 *
 * Events that can never succeed (unreadable payload, conflicting link) are marked processed with
 * that outcome instead of failing forever; only transient errors (database) propagate for a retry.
 */
final readonly class ProcessProviderEvent
{
    public function __construct(
        private ProviderEventInbox $inbox,
        private TransactionRunner $transactions,
        private ApplyProviderUpdate $applyProviderUpdate,
    ) {}

    /**
     * @return ProviderEventOutcome|null null when the event was already processed (or never recorded)
     */
    public function handle(string $eventId): ?ProviderEventOutcome
    {
        return $this->transactions->run(function () use ($eventId): ?ProviderEventOutcome {
            try {
                $event = $this->inbox->findUnprocessedForUpdate($eventId);

                if ($event === null) {
                    return null;
                }

                $outcome = match (true) {
                    $event->update === null => ProviderEventOutcome::Ignored,
                    $this->applyProviderUpdate->handle($event->update) => ProviderEventOutcome::Applied,
                    default => ProviderEventOutcome::UnknownGeneration,
                };
            } catch (MalformedProviderPayload) {
                $outcome = ProviderEventOutcome::Malformed;
            } catch (ProviderLinkConflict) {
                $outcome = ProviderEventOutcome::Conflict;
            }

            $this->inbox->markProcessed($eventId, $outcome);

            return $outcome;
        });
    }
}
