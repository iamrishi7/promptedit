<?php

use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\UseCases\AbandonSubmission;
use App\Application\UseCases\ApplyProviderUpdate;
use App\Application\UseCases\StartGeneration;
use App\Application\UseCases\SubmitGeneration;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\ProviderUpdate;
use App\Domain\Generation\Quality;
use Tests\Fakes\FakeImageGenerationProvider;
use Tests\Fakes\ImmediateTransactionRunner;
use Tests\Fakes\InMemoryGenerationRepository;

beforeEach(function () {
    $this->generations = new InMemoryGenerationRepository;
    $this->provider = new FakeImageGenerationProvider(pixelforgeDocsPricing());
    $transactions = new ImmediateTransactionRunner;
    $markup = new Markup(25);

    $this->start = new StartGeneration($this->generations, $this->provider, $markup);
    $this->submit = new SubmitGeneration($this->generations, $this->provider, $transactions, new ApplyProviderUpdate($this->generations, $transactions));
    $this->abandon = new AbandonSubmission($this->generations, $transactions);

    $this->generationId = $this->start->handle(7, '0193a5c2-0000-7000-8000-000000000001', new GenerationRequest(str_repeat('a', 400), ImageSize::Landscape, Quality::High, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]))->generationId;
});

it('stores a pending generation with its estimate before contacting the provider', function () {
    $generation = $this->generations->find($this->generationId);

    expect($generation?->status())->toBe(GenerationStatus::Pending)
        ->and($generation?->userId)->toBe(7)
        ->and($this->generations->estimates[$this->generationId]->maximumCharge->nanos)->toBe(467_343_750)
        ->and($this->provider->submitted)->toBe([]);
});

it('submits with the generation id and records the provider id and status', function () {
    $this->provider->willRespondTo(new ProviderUpdate('gen_abc', GenerationStatus::Queued, $this->generationId));

    $this->submit->handle($this->generationId);

    $generation = $this->generations->find($this->generationId);
    expect($this->provider->submitted)->toBe([$this->generationId])
        ->and($generation?->providerId())->toBe('gen_abc')
        ->and($generation?->status())->toBe(GenerationStatus::Queued);
});

it('fails for free when the provider refuses the prompt', function () {
    $this->provider->willRespondTo(new ProviderRefused(new GenerationFailure('content_policy_violation', 'The prompt was rejected.')));

    $this->submit->handle($this->generationId);

    $generation = $this->generations->find($this->generationId);
    expect($generation?->status())->toBe(GenerationStatus::Failed)
        ->and($generation?->failure()?->code)->toBe('content_policy_violation')
        ->and($generation?->cost()?->charge->isZero())->toBeTrue();
});

it('leaves the generation pending and lets the caller retry when the provider is unavailable', function () {
    $this->provider->willRespondTo(new ProviderUnavailable('overloaded', 2));

    expect(fn () => $this->submit->handle($this->generationId))->toThrow(ProviderUnavailable::class)
        ->and($this->generations->find($this->generationId)?->status())->toBe(GenerationStatus::Pending);
});

it('does not submit again once the generation was accepted', function () {
    $this->submit->handle($this->generationId);
    $this->submit->handle($this->generationId);

    expect($this->provider->submitted)->toHaveCount(1);
});

it('keeps the newer status when a webhook already moved the generation on before the submit response', function () {
    $this->provider->willRespondTo(new ProviderUpdate('gen_abc', GenerationStatus::Queued, $this->generationId));
    $applyWebhook = new ApplyProviderUpdate($this->generations, new ImmediateTransactionRunner);

    $applyWebhook->handle(new ProviderUpdate('gen_abc', GenerationStatus::Processing, $this->generationId));
    $this->submit->handle($this->generationId);

    expect($this->provider->submitted)->toBe([])
        ->and($this->generations->find($this->generationId)?->status())->toBe(GenerationStatus::Processing);
});

it('remembers that a timed-out attempt may have reached Pixelforge', function () {
    $this->provider->willRespondTo(new ProviderUnavailable('connection_failed', outcomeUnknown: true));

    expect(fn () => $this->submit->handle($this->generationId))->toThrow(ProviderUnavailable::class);

    $generation = $this->generations->find($this->generationId);
    expect($generation?->status())->toBe(GenerationStatus::Pending)
        ->and($generation?->isSubmissionOutcomeUnknown())->toBeTrue();
});

it('abandons as unconfirmed after a timed-out attempt', function () {
    $this->provider->willRespondTo(new ProviderUnavailable('connection_failed', outcomeUnknown: true));
    rescue(fn () => $this->submit->handle($this->generationId), report: false);

    $this->abandon->handle($this->generationId);

    expect($this->generations->find($this->generationId)?->failure()?->code)->toBe(GenerationFailure::SUBMISSION_UNCONFIRMED);
});

it('abandons a submission Pixelforge kept refusing as busy, for free', function () {
    $this->provider->willRespondTo(new ProviderUnavailable('overloaded', retryAfterSeconds: 2));
    rescue(fn () => $this->submit->handle($this->generationId), report: false);

    $this->abandon->handle($this->generationId);

    $generation = $this->generations->find($this->generationId);
    expect($generation?->status())->toBe(GenerationStatus::Failed)
        ->and($generation?->failure()?->code)->toBe(GenerationFailure::SUBMISSION_ABANDONED)
        ->and($generation?->cost()?->charge->isZero())->toBeTrue();
});

it('says a submission was never sent when nothing ever reached Pixelforge', function () {
    $this->abandon->handle($this->generationId);

    $generation = $this->generations->find($this->generationId);
    expect($generation?->failure()?->code)->toBe(GenerationFailure::SUBMISSION_NOT_SENT)
        ->and($generation?->cost()?->charge->isZero())->toBeTrue();
});

it('records the attempt before sending, and sends nothing once the generation was abandoned', function () {
    $this->provider->willRespondTo(new ProviderUpdate('gen_abc', GenerationStatus::Queued, $this->generationId));
    $this->abandon->handle($this->generationId);

    $this->submit->handle($this->generationId);

    expect($this->provider->submitted)->toBe([])
        ->and($this->generations->find($this->generationId)?->submissionAttempts())->toBe(0);
});

it('counts an attempt even when it ends without an answer, so abandoning it stays provisional', function () {
    $this->provider->willRespondTo(new RuntimeException('worker killed mid-request'));
    rescue(fn () => $this->submit->handle($this->generationId), report: false);

    $this->abandon->handle($this->generationId, onlyIfAttempted: true);

    expect($this->generations->find($this->generationId)?->failure()?->code)->toBe(GenerationFailure::SUBMISSION_UNCONFIRMED);
});

it('leaves a never-attempted submission pending when a job gives up, for the poller to retry', function () {
    $this->abandon->handle($this->generationId, onlyIfAttempted: true);

    expect($this->generations->find($this->generationId)?->status())->toBe(GenerationStatus::Pending);
});

it('does not abandon a generation the provider accepted', function () {
    $this->submit->handle($this->generationId);

    $this->abandon->handle($this->generationId);

    expect($this->generations->find($this->generationId)?->status())->toBe(GenerationStatus::Queued);
});
