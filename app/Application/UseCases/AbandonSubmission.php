<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\TransactionRunner;

/**
 * Stop trying to submit a generation after temporary provider errors lasted too long. The user is
 * not charged. If an attempt's outcome was unknown, the failure stays provisional so Pixelforge's
 * own report (webhook or reconciliation) can still restore the truth.
 *
 * A job that gives up without any attempt reaching Pixelforge (it waited out its retry window in a
 * backed-up queue) passes $onlyIfAttempted: the generation stays pending and the poller re-queues it.
 */
final readonly class AbandonSubmission
{
    public function __construct(
        private GenerationRepository $generations,
        private TransactionRunner $transactions,
    ) {}

    public function handle(string $generationId, bool $onlyIfAttempted = false): void
    {
        $this->transactions->run(function () use ($generationId, $onlyIfAttempted): void {
            $generation = $this->generations->findForUpdate($generationId);

            if ($generation === null || ($onlyIfAttempted && ! $generation->wasSubmissionAttempted())) {
                return;
            }

            if ($generation->abandonSubmission()) {
                $this->generations->save($generation);
            }
        });
    }
}
