<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageGenerationProvider;
use App\Application\Ports\TransactionRunner;
use App\Domain\Generation\Generation;

/**
 * Send a pending generation to Pixelforge. Safe to run more than once: the generation id is the
 * idempotency key, and a generation that already moved on is left alone.
 */
final readonly class SubmitGeneration
{
    public function __construct(
        private GenerationRepository $generations,
        private ImageGenerationProvider $provider,
        private TransactionRunner $transactions,
        private ApplyProviderUpdate $applyProviderUpdate,
    ) {}

    /**
     * @throws ProviderUnavailable when the caller should retry later
     */
    public function handle(string $generationId): void
    {
        $generation = $this->generations->find($generationId);

        if ($generation === null || ! $generation->isAwaitingSubmission()) {
            return;
        }

        // Recorded under the row lock before the request goes out. If the generation was abandoned
        // meanwhile, nothing is sent; if this attempt's answer is never recorded, abandoning it later
        // stays provisional, so a generation Pixelforge did create is still billed when it reports.
        if (! $this->changeLocked($generationId, fn (Generation $locked): bool => $locked->beginSubmissionAttempt())) {
            return;
        }

        try {
            $update = $this->provider->submit($generation->id, $generation->request);
        } catch (ProviderRefused $refused) {
            $this->changeLocked($generationId, fn (Generation $locked): bool => $locked->failBeforeAcceptance($refused->failure));

            return;
        } catch (ProviderUnavailable $unavailable) {
            $this->changeLocked($generationId, fn (Generation $locked): bool => $unavailable->outcomeUnknown
                ? $locked->recordUncertainSubmission()
                : $locked->recordBusyRefusal());

            throw $unavailable;
        } catch (MalformedProviderPayload $malformed) {
            // Pixelforge accepted something we could not read: it may well exist, so never treat it as definitely not created.
            $this->changeLocked($generationId, fn (Generation $locked): bool => $locked->recordUncertainSubmission());

            throw $malformed;
        }

        $this->applyProviderUpdate->handle($update);
    }

    /**
     * @param  callable(Generation): bool  $change  returns whether anything changed
     * @return bool whether anything changed
     */
    private function changeLocked(string $generationId, callable $change): bool
    {
        return $this->transactions->run(function () use ($generationId, $change): bool {
            $generation = $this->generations->findForUpdate($generationId);

            if ($generation === null || ! $change($generation)) {
                return false;
            }

            $this->generations->save($generation);

            return true;
        });
    }
}
