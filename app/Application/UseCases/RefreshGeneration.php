<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageGenerationProvider;
use DateTimeImmutable;

/**
 * Fallback for missed webhooks: ask Pixelforge for an in-flight generation's current state and apply
 * it through the same path as webhooks. The HTTP call happens outside any transaction or lock.
 */
final readonly class RefreshGeneration
{
    public function __construct(
        private GenerationRepository $generations,
        private ImageGenerationProvider $provider,
        private ApplyProviderUpdate $applyProviderUpdate,
    ) {}

    /**
     * @throws ProviderUnavailable when Pixelforge is busy; stop polling for now
     * @throws ProviderRefused|MalformedProviderPayload for this generation only; it is retried next round
     */
    public function handle(string $generationId, DateTimeImmutable $now): void
    {
        $providerId = $this->generations->find($generationId)?->providerId();

        if ($providerId === null) {
            return;
        }

        try {
            $this->applyProviderUpdate->handle($this->provider->fetch($providerId));
        } finally {
            $this->generations->markChecked($generationId, $now);
        }
    }
}
