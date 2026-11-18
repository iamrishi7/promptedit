<?php

declare(strict_types=1);

use App\Domain\Generation\GenerationFailure;

it('explains Pixelforge failures in plain language and says the user was not charged', function (string $code) {
    $message = (new GenerationFailure($code, 'raw developer message from Pixelforge'))->userMessage();

    expect($message)->not->toContain('raw developer message')
        ->and($message)->toContain('You were not charged');
})->with(['generation_failed', 'internal_error', 'content_policy_violation', 'some_future_code']);

it('keeps the user-facing messages we wrote ourselves', function (GenerationFailure $failure) {
    expect($failure->userMessage())->toBe($failure->message);
})->with([
    'abandoned' => fn () => GenerationFailure::submissionAbandoned(),
    'unconfirmed' => fn () => GenerationFailure::submissionUnconfirmed(),
    'not found' => fn () => GenerationFailure::submissionNotFound(),
    'rejected' => fn () => new GenerationFailure(GenerationFailure::PROVIDER_REJECTED_REQUEST, 'Pixelforge could not accept this request. You were not charged.'),
    'daily limit' => fn () => new GenerationFailure('daily_limit_exceeded', 'Pixelforge\'s daily generation limit is used up. Try again after midnight UTC. You were not charged.'),
]);

it('never claims an unconfirmed submission was free', function () {
    expect(GenerationFailure::submissionUnconfirmed()->userMessage())->not->toContain('not charged');
});
