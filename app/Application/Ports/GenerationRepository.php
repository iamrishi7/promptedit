<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Data\UnconfirmedSubmission;
use App\Domain\Billing\CostEstimate;
use App\Domain\Generation\Generation;
use DateTimeImmutable;

interface GenerationRepository
{
    public function nextIdentity(): string;

    /**
     * Store a new generation together with the estimate shown to the user before submitting, unless
     * the same user already started one for this client request id (a repeated form submission).
     *
     * @return string the id of the generation that owns the client request: the new one, or the existing one
     */
    public function add(Generation $generation, CostEstimate $estimate, string $clientRequestId): string;

    public function find(string $id): ?Generation;

    /**
     * The generation a user already started for this client request id, if any.
     */
    public function findByClientRequest(int $userId, string $clientRequestId): ?Generation;

    /**
     * Find and lock a generation until the surrounding transaction ends.
     */
    public function findForUpdate(string $id): ?Generation;

    /**
     * Find and lock a generation by Pixelforge's id until the surrounding transaction ends.
     */
    public function findByProviderIdForUpdate(string $providerId): ?Generation;

    /**
     * Persist state changes. Only accepts a generation loaded with a ...ForUpdate method inside the
     * current transaction, so a stale read can never overwrite newer state.
     */
    public function save(Generation $generation): void;

    /**
     * Generations still queued or processing at Pixelforge with no update and no check since the cutoff,
     * least recently checked first.
     *
     * @return list<string> generation ids
     */
    public function inFlightNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Unconfirmed submissions not checked since the cutoff, least recently checked first.
     *
     * @return list<UnconfirmedSubmission>
     */
    public function unconfirmedNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Generations still waiting to be submitted that were created before the cutoff.
     *
     * @return list<string> generation ids
     */
    public function pendingCreatedBefore(DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Record that we asked Pixelforge about this generation (bookkeeping only, not generation state).
     */
    /**
     * Succeeded generations whose image still needs copying to our storage and can still be fetched
     * (link not expired, not marked unstorable), soonest-to-expire first.
     *
     * @return list<string> generation ids
     */
    public function succeededWithoutStoredImage(DateTimeImmutable $now, int $limit): array;

    /**
     * The image can never be stored (not an image, too large, refused): the sweep stops retrying it.
     */
    public function markImageUnstorable(string $id): void;

    public function markChecked(string $id, DateTimeImmutable $at): void;
}
