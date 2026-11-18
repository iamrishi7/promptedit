<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\TransactionRunner;
use App\Domain\Generation\ProviderUpdate;

/**
 * Apply Pixelforge's view of a generation, from a submit response, a webhook or a poll. All three
 * paths go through here so the ordering and once-only cost rules live in one place.
 */
final readonly class ApplyProviderUpdate
{
    public function __construct(
        private GenerationRepository $generations,
        private TransactionRunner $transactions,
    ) {}

    /**
     * @return bool false when no local generation matches (e.g. it belongs to another environment)
     */
    public function handle(ProviderUpdate $update): bool
    {
        return $this->transactions->run(function () use ($update): bool {
            $generation = $this->generations->findByProviderIdForUpdate($update->providerId);

            if ($generation === null && $update->generationId !== null) {
                $generation = $this->generations->findForUpdate($update->generationId);
            }

            if ($generation === null) {
                return false;
            }

            if ($generation->apply($update)) {
                $this->generations->save($generation);
            }

            return true;
        });
    }
}
