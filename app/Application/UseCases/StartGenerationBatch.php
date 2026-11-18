<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Data\StartedGeneration;
use App\Application\Exceptions\ClientRequestReused;
use App\Application\Ports\TransactionRunner;
use App\Domain\Generation\GenerationRequest;
use InvalidArgumentException;

/**
 * Start several images from one form submission. Pixelforge makes one image per request, so each
 * image is its own generation with its own estimate, idempotency key and charge. All of them are
 * recorded in one transaction: either every image starts or none does (nothing half-paid).
 *
 * The caller gives one client request id per image, derived from the form's id, so a repeated
 * submission maps every image back to the generation it already started.
 */
final readonly class StartGenerationBatch
{
    public const int MAX_IMAGES = 4;

    public function __construct(
        private StartGeneration $startGeneration,
        private TransactionRunner $transactions,
    ) {}

    /**
     * @param  list<string>  $clientRequestIds  one per image
     * @return list<StartedGeneration>
     *
     * @throws ClientRequestReused when an id already started a different request
     */
    public function handle(int $userId, array $clientRequestIds, GenerationRequest $request): array
    {
        $count = count($clientRequestIds);

        if ($count < 1 || $count > self::MAX_IMAGES || count(array_unique($clientRequestIds)) !== $count) {
            throw new InvalidArgumentException('A batch needs 1 to '.self::MAX_IMAGES.' distinct client request ids, got '.$count.'.');
        }

        return $this->transactions->run(fn (): array => array_map(
            fn (string $clientRequestId): StartedGeneration => $this->startGeneration->handle($userId, $clientRequestId, $request),
            $clientRequestIds,
        ));
    }
}
