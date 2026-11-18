<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Data\UnconfirmedSubmission;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageGenerationProvider;
use App\Application\Ports\TransactionRunner;
use DateTimeImmutable;

/**
 * Resolve a submission whose outcome we never saw, by looking it up in Pixelforge's own records
 * (never by submitting again, which would create it now if the first attempt never arrived).
 *
 * Found: Pixelforge's state replaces our provisional failure, and it is billed if it succeeded.
 * Not found: the failure becomes final and the user is definitely not charged.
 */
final readonly class ConfirmUnconfirmedSubmission
{
    /** Allow for clock differences between us and Pixelforge when deciding the lookup went back far enough. */
    private const string CLOCK_MARGIN = '-5 minutes';

    public function __construct(
        private GenerationRepository $generations,
        private ImageGenerationProvider $provider,
        private TransactionRunner $transactions,
        private ApplyProviderUpdate $applyProviderUpdate,
    ) {}

    /**
     * @throws ProviderUnavailable when Pixelforge is busy or the lookup was incomplete; retried next round
     * @throws ProviderRefused|MalformedProviderPayload for this generation only; retried next round
     */
    public function handle(UnconfirmedSubmission $submission, DateTimeImmutable $now): void
    {
        try {
            $update = $this->provider->findSubmitted($submission->generationId, $submission->createdAt->modify(self::CLOCK_MARGIN));

            if ($update !== null) {
                $this->applyProviderUpdate->handle($update);

                return;
            }

            $this->transactions->run(function () use ($submission): void {
                $generation = $this->generations->findForUpdate($submission->generationId);

                if ($generation?->confirmNotAccepted()) {
                    $this->generations->save($generation);
                }
            });
        } finally {
            $this->generations->markChecked($submission->generationId, $now);
        }
    }
}
