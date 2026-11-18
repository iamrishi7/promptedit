<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Billing\GenerationCost;
use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;
use DateTimeImmutable;
use DomainException;

/**
 * One user's image generation and the rules for changing it.
 *
 * Pixelforge may report the same state twice, report states out of order, or report before our own
 * submit call returns. Applying an update is therefore idempotent and monotonic: status only moves
 * forward, a final state never changes (except a provisional failure, see below), and the cost is
 * recorded exactly once, with the markup fixed when the generation was created.
 */
final class Generation
{
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly GenerationRequest $request,
        public readonly Markup $markup,
        private GenerationStatus $status = GenerationStatus::Pending,
        private ?string $providerId = null,
        private bool $submissionOutcomeUnknown = false,
        private ?TokenUsage $usage = null,
        private ?GenerationCost $cost = null,
        private ?string $imageUrl = null,
        private ?DateTimeImmutable $imageUrlExpiresAt = null,
        private ?GenerationFailure $failure = null,
        private ?DateTimeImmutable $completedAt = null,
        private int $submissionAttempts = 0,
        private int $busyRefusals = 0,
        private ?string $storedImagePath = null,
    ) {}

    /**
     * Apply what Pixelforge reports. Returns whether anything changed.
     */
    public function apply(ProviderUpdate $update): bool
    {
        $replacesProvisionalFailure = $this->hasProvisionalFailure();
        $changed = $this->linkToProvider($update->providerId);

        if (! $replacesProvisionalFailure && ($this->status->isTerminal() || ! $this->status->isBefore($update->status))) {
            return $changed;
        }

        $this->status = $update->status;
        $this->failure = null;
        $this->cost = null;
        $this->completedAt = null;

        if ($update->status === GenerationStatus::Succeeded) {
            $this->usage = $update->usage ?? throw new DomainException('A succeeded update always has usage.');
            $this->cost = GenerationCost::forUsage($this->usage, $update->rates ?? throw new DomainException('A succeeded update always has rates.'), $this->markup);
            $this->imageUrl = $update->imageUrl;
            $this->imageUrlExpiresAt = $update->imageUrlExpiresAt;
            $this->completedAt = $update->completedAt;
        }

        if ($update->status === GenerationStatus::Failed) {
            $this->failure = $update->failure;
            $this->cost = GenerationCost::free();
            $this->completedAt = $update->completedAt;
        }

        return true;
    }

    /**
     * Remember that a submit attempt may have reached Pixelforge without us seeing the answer
     * (timeout, server error). Returns whether anything changed.
     */
    public function recordUncertainSubmission(): bool
    {
        if ($this->status !== GenerationStatus::Pending || $this->submissionOutcomeUnknown) {
            return false;
        }

        $this->submissionOutcomeUnknown = true;

        return true;
    }

    /**
     * Record that a submit request is about to be sent, before it is sent, so an attempt whose
     * answer never gets recorded (worker killed mid-request, answer lost) is still known. Returns
     * false when the generation is no longer waiting to be submitted: the caller must not send.
     */
    public function beginSubmissionAttempt(): bool
    {
        if ($this->status !== GenerationStatus::Pending) {
            return false;
        }

        $this->submissionAttempts++;

        return true;
    }

    /**
     * Pixelforge answered the current attempt with "busy" (rate limit, concurrency limit,
     * overloaded): that attempt created nothing. Returns whether anything changed.
     */
    public function recordBusyRefusal(): bool
    {
        if ($this->status !== GenerationStatus::Pending || $this->busyRefusals >= $this->submissionAttempts) {
            return false;
        }

        $this->busyRefusals++;

        return true;
    }

    public function wasSubmissionAttempted(): bool
    {
        return $this->submissionAttempts > 0 || $this->submissionOutcomeUnknown;
    }

    /**
     * Stop trying to submit. The free failure says what actually happened:
     * - some attempt has no definitive answer (timeout, lost answer, killed mid-request): it may
     *   have been created, so the failure is provisional until Pixelforge's records settle it;
     * - every attempt was answered "busy": Pixelforge never created the generation;
     * - no attempt was ever sent (e.g. our queue was backed up): it was never sent.
     */
    public function abandonSubmission(): bool
    {
        return $this->failBeforeAcceptance(match (true) {
            $this->submissionOutcomeUnknown || $this->submissionAttempts > $this->busyRefusals => GenerationFailure::submissionUnconfirmed(),
            $this->submissionAttempts > 0 => GenerationFailure::submissionAbandoned(),
            default => GenerationFailure::submissionNotSent(),
        });
    }

    /**
     * A succeeded generation whose image is still only at Pixelforge's link, which expires 24 hours
     * after completion.
     */
    public function needsImageStored(): bool
    {
        return $this->status === GenerationStatus::Succeeded && $this->imageUrl !== null && $this->storedImagePath === null;
    }

    /**
     * Remember where our own copy of the image is. Recorded once. Returns whether anything changed.
     */
    public function recordStoredImage(string $path): bool
    {
        if (! $this->needsImageStored()) {
            return false;
        }

        $this->storedImagePath = $path;

        return true;
    }

    /**
     * Pixelforge's records show an unconfirmed submission never reached it: the provisional failure
     * becomes final (and stays free). Returns whether anything changed.
     */
    public function confirmNotAccepted(): bool
    {
        if (! $this->hasProvisionalFailure()) {
            return false;
        }

        $this->failure = GenerationFailure::submissionNotFound();

        return true;
    }

    public function hasUnconfirmedSubmission(): bool
    {
        return $this->hasProvisionalFailure();
    }

    /**
     * Fail a generation Pixelforge never accepted. It is free. Returns whether anything changed.
     */
    public function failBeforeAcceptance(GenerationFailure $failure): bool
    {
        if ($this->status !== GenerationStatus::Pending) {
            return false;
        }

        $this->status = GenerationStatus::Failed;
        $this->failure = $failure;
        $this->cost = GenerationCost::free();

        return true;
    }

    public function isAwaitingSubmission(): bool
    {
        return $this->status === GenerationStatus::Pending;
    }

    public function status(): GenerationStatus
    {
        return $this->status;
    }

    public function providerId(): ?string
    {
        return $this->providerId;
    }

    public function isSubmissionOutcomeUnknown(): bool
    {
        return $this->submissionOutcomeUnknown;
    }

    public function usage(): ?TokenUsage
    {
        return $this->usage;
    }

    public function cost(): ?GenerationCost
    {
        return $this->cost;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function imageUrlExpiresAt(): ?DateTimeImmutable
    {
        return $this->imageUrlExpiresAt;
    }

    public function submissionAttempts(): int
    {
        return $this->submissionAttempts;
    }

    public function busyRefusals(): int
    {
        return $this->busyRefusals;
    }

    public function storedImagePath(): ?string
    {
        return $this->storedImagePath;
    }

    public function failure(): ?GenerationFailure
    {
        return $this->failure;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Pixelforge is the source of truth for what was billed, so its report replaces a failure we only
     * assumed (a submit whose outcome we never saw may have been accepted after all).
     */
    private function hasProvisionalFailure(): bool
    {
        return $this->status === GenerationStatus::Failed
            && $this->providerId === null
            && $this->failure?->isProvisional() === true;
    }

    private function linkToProvider(string $providerId): bool
    {
        if ($this->providerId === null) {
            $this->providerId = $providerId;

            return true;
        }

        if ($this->providerId !== $providerId) {
            throw ProviderLinkConflict::between($this->id, $this->providerId, $providerId);
        }

        return false;
    }
}
