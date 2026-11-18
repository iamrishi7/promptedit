<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Data\StartedGeneration;
use App\Application\Exceptions\ClientRequestReused;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationRequest;

/**
 * Record a new generation with its estimate before anything is sent to the provider, so its id can
 * serve as the idempotency key and a webhook arriving early can always be matched. The current
 * markup is fixed on the generation, so the user pays the markup they were quoted.
 *
 * The client request id identifies one form submission: submitting it again (double click, network
 * retry) returns the generation already started for it instead of starting and paying for another.
 */
final readonly class StartGeneration
{
    public function __construct(
        private GenerationRepository $generations,
        private ImageGenerationProvider $provider,
        private Markup $markup,
    ) {}

    /**
     * @throws ClientRequestReused when the client request id already started a different request
     */
    public function handle(int $userId, string $clientRequestId, GenerationRequest $request): StartedGeneration
    {
        // A repeat needs no price: it must succeed even while Pixelforge's pricing is unavailable.
        $existing = $this->generations->findByClientRequest($userId, $clientRequestId);

        if ($existing !== null) {
            return $this->repeated($existing, $request);
        }

        $estimate = CostEstimate::for($request, $this->provider->pricing(), $this->markup);

        $generation = new Generation($this->generations->nextIdentity(), $userId, $request, $this->markup);
        $generationId = $this->generations->add($generation, $estimate, $clientRequestId);

        if ($generationId !== $generation->id) {
            // A concurrent duplicate inserted first.
            return $this->repeated($this->generations->find($generationId) ?? throw new ClientRequestReused($generationId), $request);
        }

        return new StartedGeneration($generationId, isNew: true);
    }

    private function repeated(Generation $existing, GenerationRequest $request): StartedGeneration
    {
        if (! $existing->request->equals($request)) {
            throw new ClientRequestReused($existing->id);
        }

        return new StartedGeneration($existing->id, isNew: false);
    }
}
