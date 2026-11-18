<?php

declare(strict_types=1);

namespace App\Domain\Generation;

/**
 * Why a generation failed: a Pixelforge error code (e.g. generation_failed, content_policy_violation)
 * or one of our own codes for failures that happen before Pixelforge accepts the request.
 */
final readonly class GenerationFailure
{
    /** We stopped retrying after Pixelforge kept answering "busy"; it never created the generation. */
    public const string SUBMISSION_ABANDONED = 'submission_abandoned';

    /** We stopped retrying after a timeout or server error; Pixelforge may have created it after all. */
    public const string SUBMISSION_UNCONFIRMED = 'submission_unconfirmed';

    /** Pixelforge's own records show it never received the unconfirmed submission. */
    public const string SUBMISSION_NOT_FOUND = 'submission_not_found';

    /** We gave up before any attempt reached Pixelforge (e.g. our queue was backed up for too long). */
    public const string SUBMISSION_NOT_SENT = 'submission_not_sent';

    /** Pixelforge rejected our request as invalid or unauthorised (a bug or misconfiguration on our side). */
    public const string PROVIDER_REJECTED_REQUEST = 'provider_rejected_request';

    public function __construct(public string $code, public string $message) {}

    public static function submissionAbandoned(): self
    {
        return new self(self::SUBMISSION_ABANDONED, 'Pixelforge stayed too busy to accept this generation, so we stopped trying. You were not charged.');
    }

    public static function submissionUnconfirmed(): self
    {
        return new self(self::SUBMISSION_UNCONFIRMED, 'We could not confirm with Pixelforge whether this generation started. We are checking; you will only be charged if it completed.');
    }

    public static function submissionNotSent(): self
    {
        return new self(self::SUBMISSION_NOT_SENT, 'We couldn\'t send this generation to Pixelforge in time, so we stopped. You were not charged. Try again.');
    }

    public static function submissionNotFound(): self
    {
        return new self(self::SUBMISSION_NOT_FOUND, 'Pixelforge confirmed this generation never started. You were not charged.');
    }

    /**
     * What to tell the user: what went wrong, whether they pay, and what to do next. Our own codes
     * (and the daily limit) already store a message written for users; Pixelforge's messages are
     * written for developers, so known codes get our own wording and unknown ones a safe default.
     */
    public function userMessage(): string
    {
        return match ($this->code) {
            'generation_failed' => 'Pixelforge could not create an image for this prompt. You were not charged. Try rewording it.',
            'internal_error' => 'Pixelforge had an internal error. You were not charged. Trying again usually works.',
            'content_policy_violation' => 'Pixelforge blocked this prompt under its content policy. You were not charged.',
            self::SUBMISSION_ABANDONED,
            self::SUBMISSION_UNCONFIRMED,
            self::SUBMISSION_NOT_FOUND,
            self::SUBMISSION_NOT_SENT,
            self::PROVIDER_REJECTED_REQUEST,
            'daily_limit_exceeded' => $this->message,
            default => 'This generation failed. You were not charged.',
        };
    }

    /**
     * A provisional failure is our best guess while Pixelforge's answer is unknown. Pixelforge's own
     * report about the generation, if one ever arrives, replaces it.
     */
    public function isProvisional(): bool
    {
        return $this->code === self::SUBMISSION_UNCONFIRMED;
    }
}
