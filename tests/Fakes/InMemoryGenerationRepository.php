<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\GenerationRepository;
use App\Domain\Billing\CostEstimate;
use App\Domain\Generation\Generation;
use DateTimeImmutable;
use LogicException;

/**
 * Stores copies, like a database would, so a use case that forgets to save() is caught by tests.
 */
final class InMemoryGenerationRepository implements GenerationRepository
{
    /** @var array<string, Generation> */
    private array $generations = [];

    /** @var array<string, CostEstimate> */
    public array $estimates = [];

    public int $saves = 0;

    /** @var array<string, string> generation id by "userId|clientRequestId" */
    private array $clientRequests = [];

    private int $sequence = 0;

    /** @var array<string, true> */
    private array $lockedIds = [];

    public function nextIdentity(): string
    {
        $this->sequence++;

        return sprintf('00000000-0000-7000-8000-%012d', $this->sequence);
    }

    public function add(Generation $generation, CostEstimate $estimate, ?string $clientRequestId = null): string
    {
        $clientRequestKey = $generation->userId.'|'.($clientRequestId ?? $generation->id);

        if (isset($this->clientRequests[$clientRequestKey])) {
            return $this->clientRequests[$clientRequestKey];
        }

        $this->clientRequests[$clientRequestKey] = $generation->id;
        $this->generations[$generation->id] = clone $generation;
        $this->estimates[$generation->id] = $estimate;

        return $generation->id;
    }

    public function find(string $id): ?Generation
    {
        return isset($this->generations[$id]) ? clone $this->generations[$id] : null;
    }

    public function findByClientRequest(int $userId, string $clientRequestId): ?Generation
    {
        $id = $this->clientRequests[$userId.'|'.$clientRequestId] ?? null;

        return $id === null ? null : $this->find($id);
    }

    public function findForUpdate(string $id): ?Generation
    {
        if (isset($this->generations[$id])) {
            $this->lockedIds[$id] = true;
        }

        return $this->find($id);
    }

    public function findByProviderIdForUpdate(string $providerId): ?Generation
    {
        foreach ($this->generations as $generation) {
            if ($generation->providerId() === $providerId) {
                $this->lockedIds[$generation->id] = true;

                return clone $generation;
            }
        }

        return null;
    }

    public function save(Generation $generation): void
    {
        if (! isset($this->lockedIds[$generation->id])) {
            throw new LogicException("Generation {$generation->id} was saved without being loaded for update.");
        }

        $this->saves++;
        $this->generations[$generation->id] = clone $generation;
    }

    public function inFlightNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array
    {
        return [];
    }

    public function unconfirmedNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array
    {
        return [];
    }

    public function pendingCreatedBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        return [];
    }

    /** @var array<string, true> */
    public array $unstorableImages = [];

    public function succeededWithoutStoredImage(DateTimeImmutable $now, int $limit): array
    {
        $ids = array_keys(array_filter(
            $this->generations,
            fn (Generation $generation): bool => $generation->needsImageStored()
                && ! isset($this->unstorableImages[$generation->id])
                && $generation->imageUrlExpiresAt() > $now,
        ));

        return array_slice(array_map('strval', $ids), 0, $limit);
    }

    public function markImageUnstorable(string $id): void
    {
        $this->unstorableImages[$id] = true;
    }

    public function markChecked(string $id, DateTimeImmutable $at): void {}
}
