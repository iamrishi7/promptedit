<?php

use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\ProviderUpdate;
use App\Domain\Generation\Quality;

function newGeneration(): Generation
{
    return new Generation('0193a7c0-0000-7000-8000-000000000001', 1, new GenerationRequest('A lighthouse on a cliff at dawn', ImageSize::Landscape, Quality::High), new Markup(25));
}

function providerUpdate(GenerationStatus $status, string $providerId = 'gen_1'): ProviderUpdate
{
    return match ($status) {
        GenerationStatus::Succeeded => new ProviderUpdate(
            providerId: $providerId,
            status: $status,
            usage: new TokenUsage(12, 765, 6450, 3184),
            rates: pixelforgeDocsRates(),
            imageUrl: 'https://pixelforge.promptedit.dev/images/gen_1.png',
            imageUrlExpiresAt: new DateTimeImmutable('2026-11-05T14:03:29Z'),
            completedAt: new DateTimeImmutable('2026-11-04T14:03:29Z'),
        ),
        GenerationStatus::Failed => new ProviderUpdate(
            providerId: $providerId,
            status: $status,
            failure: new GenerationFailure('internal_error', 'Something went wrong on our side.'),
            completedAt: new DateTimeImmutable('2026-11-04T14:03:20Z'),
        ),
        default => new ProviderUpdate($providerId, $status),
    };
}

it('records the cost and charge from usage when the generation succeeds', function () {
    $generation = newGeneration();

    $changed = $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($changed)->toBeTrue()
        ->and($generation->status())->toBe(GenerationStatus::Succeeded)
        ->and($generation->providerId())->toBe('gen_1')
        ->and($generation->cost()?->providerCost->nanos)->toBe(305_510_000)
        ->and($generation->cost()?->charge->nanos)->toBe(381_887_500)
        ->and($generation->imageUrl())->toBe('https://pixelforge.promptedit.dev/images/gen_1.png');
});

it('ignores a duplicate succeeded update', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    $changed = $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($changed)->toBeFalse()
        ->and($generation->cost()?->charge->nanos)->toBe(381_887_500);
});

it('never moves back from a final state when an older update arrives late', function (GenerationStatus $final, GenerationStatus $late) {
    $generation = newGeneration();
    $generation->apply(providerUpdate($final));

    $changed = $generation->apply(providerUpdate($late));

    expect($changed)->toBeFalse()
        ->and($generation->status())->toBe($final);
})->with([
    'processing after succeeded' => [GenerationStatus::Succeeded, GenerationStatus::Processing],
    'queued after succeeded' => [GenerationStatus::Succeeded, GenerationStatus::Queued],
    'processing after failed' => [GenerationStatus::Failed, GenerationStatus::Processing],
    'failed after succeeded' => [GenerationStatus::Succeeded, GenerationStatus::Failed],
    'succeeded after failed' => [GenerationStatus::Failed, GenerationStatus::Succeeded],
]);

it('does not move back from processing to queued', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Processing));

    expect($generation->apply(providerUpdate(GenerationStatus::Queued)))->toBeFalse()
        ->and($generation->status())->toBe(GenerationStatus::Processing);
});

it('jumps straight to succeeded when intermediate updates are missed', function () {
    $generation = newGeneration();

    $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($generation->status())->toBe(GenerationStatus::Succeeded);
});

it('costs nothing when Pixelforge reports a failure', function () {
    $generation = newGeneration();

    $generation->apply(providerUpdate(GenerationStatus::Failed));

    expect($generation->status())->toBe(GenerationStatus::Failed)
        ->and($generation->cost()?->charge->isZero())->toBeTrue()
        ->and($generation->failure()?->code)->toBe('internal_error');
});

it('fails a rejected submission for free', function () {
    $generation = newGeneration();

    $changed = $generation->failBeforeAcceptance(new GenerationFailure('content_policy_violation', 'The prompt was rejected.'));

    expect($changed)->toBeTrue()
        ->and($generation->status())->toBe(GenerationStatus::Failed)
        ->and($generation->cost()?->charge->isZero())->toBeTrue();
});

it('does not fail a generation Pixelforge already accepted', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Queued));

    expect($generation->failBeforeAcceptance(GenerationFailure::submissionAbandoned()))->toBeFalse()
        ->and($generation->status())->toBe(GenerationStatus::Queued);
});

it('abandons as final when every attempt was refused as busy', function () {
    $generation = newGeneration();
    expect($generation->recordBusyRefusal())->toBeFalse(); // no attempt to answer yet

    foreach ([1, 2] as $attempt) {
        expect($generation->beginSubmissionAttempt())->toBeTrue()
            ->and($generation->recordBusyRefusal())->toBeTrue()
            ->and($generation->recordBusyRefusal())->toBeFalse();
    }
    expect($generation->wasSubmissionAttempted())->toBeTrue();

    $generation->abandonSubmission();

    expect($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_ABANDONED)
        ->and($generation->failure()?->isProvisional())->toBeFalse();
});

it('says it was never sent when no attempt ever reached Pixelforge', function () {
    $generation = newGeneration();
    expect($generation->wasSubmissionAttempted())->toBeFalse();

    $generation->abandonSubmission();

    expect($generation->status())->toBe(GenerationStatus::Failed)
        ->and($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_NOT_SENT)
        ->and($generation->failure()?->isProvisional())->toBeFalse()
        ->and($generation->cost()?->charge->isZero())->toBeTrue();
});

it('remembers a stored copy of the image once, and only for a succeeded image', function () {
    $generation = newGeneration();
    expect($generation->needsImageStored())->toBeFalse()
        ->and($generation->recordStoredImage('generations/1/a.png'))->toBeFalse();

    $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($generation->needsImageStored())->toBeTrue()
        ->and($generation->recordStoredImage('generations/1/a.png'))->toBeTrue()
        ->and($generation->recordStoredImage('generations/1/b.png'))->toBeFalse()
        ->and($generation->storedImagePath())->toBe('generations/1/a.png')
        ->and($generation->needsImageStored())->toBeFalse();
});

it('stays provisional when an attempt never got a definitive answer, even if a later one was busy', function () {
    $generation = newGeneration();
    $generation->beginSubmissionAttempt(); // worker killed mid-request: the answer was never recorded
    $generation->beginSubmissionAttempt();
    $generation->recordBusyRefusal();

    $generation->abandonSubmission();

    expect($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_UNCONFIRMED)
        ->and($generation->failure()?->isProvisional())->toBeTrue();
});

it('refuses to start an attempt once the generation has moved on', function () {
    $generation = newGeneration();
    $generation->abandonSubmission();

    expect($generation->beginSubmissionAttempt())->toBeFalse()
        ->and($generation->submissionAttempts())->toBe(0);
});

it('only notes a busy answer while waiting to be accepted', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Queued));

    expect($generation->recordBusyRefusal())->toBeFalse();
});

it('abandons as provisional when an attempt may have reached Pixelforge', function () {
    $generation = newGeneration();
    $generation->recordUncertainSubmission();

    $generation->abandonSubmission();

    expect($generation->status())->toBe(GenerationStatus::Failed)
        ->and($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_UNCONFIRMED)
        ->and($generation->cost()?->charge->isZero())->toBeTrue();
});

it('lets Pixelforge replace an unconfirmed failure, billing what Pixelforge billed', function () {
    $generation = newGeneration();
    $generation->recordUncertainSubmission();
    $generation->abandonSubmission();

    $changed = $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($changed)->toBeTrue()
        ->and($generation->status())->toBe(GenerationStatus::Succeeded)
        ->and($generation->failure())->toBeNull()
        ->and($generation->cost()?->charge->nanos)->toBe(381_887_500);
});

it('keeps a definite local failure final even if an update arrives', function (GenerationFailure $failure) {
    $generation = newGeneration();
    $generation->failBeforeAcceptance($failure);

    $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($generation->status())->toBe(GenerationStatus::Failed)
        ->and($generation->cost()?->charge->isZero())->toBeTrue();
})->with([
    'abandoned while busy' => [GenerationFailure::submissionAbandoned()],
    'content policy' => [new GenerationFailure('content_policy_violation', 'The prompt was rejected.')],
]);

it('only records an uncertain submission while pending', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Queued));

    expect($generation->recordUncertainSubmission())->toBeFalse()
        ->and($generation->isSubmissionOutcomeUnknown())->toBeFalse();
});

it('charges the markup fixed when the generation was created', function () {
    $generation = new Generation('0193a7c0-0000-7000-8000-000000000002', 1, new GenerationRequest('A lighthouse', ImageSize::Landscape, Quality::High), new Markup(30));

    $generation->apply(providerUpdate(GenerationStatus::Succeeded));

    expect($generation->cost()?->charge->nanos)->toBe(397_163_000);
});

it('learns the provider id even when the status is already ahead', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Processing));

    expect($generation->providerId())->toBe('gen_1');
});

it('refuses an update for a different provider generation', function () {
    $generation = newGeneration();
    $generation->apply(providerUpdate(GenerationStatus::Queued, 'gen_1'));

    $generation->apply(providerUpdate(GenerationStatus::Processing, 'gen_2'));
})->throws(DomainException::class);

it('makes an unconfirmed failure final once Pixelforge confirms it never started', function () {
    $generation = newGeneration();
    $generation->recordUncertainSubmission();
    $generation->abandonSubmission();

    expect($generation->confirmNotAccepted())->toBeTrue()
        ->and($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_NOT_FOUND)
        ->and($generation->hasUnconfirmedSubmission())->toBeFalse()
        ->and($generation->cost()?->charge->isZero())->toBeTrue();

    $generation->apply(providerUpdate(GenerationStatus::Succeeded));
    expect($generation->status())->toBe(GenerationStatus::Failed);
});

it('only confirms not-accepted for an unconfirmed failure', function () {
    $generation = newGeneration();
    $generation->beginSubmissionAttempt();
    $generation->recordBusyRefusal();
    $generation->abandonSubmission();

    expect($generation->confirmNotAccepted())->toBeFalse()
        ->and($generation->failure()?->code)->toBe(GenerationFailure::SUBMISSION_ABANDONED);
});
