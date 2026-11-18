<?php

use App\Application\UseCases\ApplyProviderUpdate;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\ProviderUpdate;
use App\Domain\Generation\Quality;
use Tests\Fakes\ImmediateTransactionRunner;
use Tests\Fakes\InMemoryGenerationRepository;

beforeEach(function () {
    $this->generations = new InMemoryGenerationRepository;
    $this->apply = new ApplyProviderUpdate($this->generations, new ImmediateTransactionRunner);

    $this->generationId = $this->generations->nextIdentity();
    $request = new GenerationRequest('A lighthouse', ImageSize::Landscape, Quality::High);
    $this->generations->add(
        new Generation($this->generationId, 1, $request, new Markup(25)),
        CostEstimate::for($request, pixelforgeDocsPricing(), new Markup(25)),
    );

    $this->succeeded = fn (?string $generationId) => new ProviderUpdate(
        providerId: 'gen_abc',
        status: GenerationStatus::Succeeded,
        generationId: $generationId,
        usage: new TokenUsage(12, 765, 6450, 3184),
        rates: pixelforgeDocsRates(),
        completedAt: new DateTimeImmutable('2026-11-04T14:03:29Z'),
    );
});

it('matches an update that arrives before the submit response by the generation id in metadata', function () {
    $applied = $this->apply->handle(($this->succeeded)($this->generationId));

    $generation = $this->generations->find($this->generationId);
    expect($applied)->toBeTrue()
        ->and($generation?->providerId())->toBe('gen_abc')
        ->and($generation?->cost()?->charge->nanos)->toBe(381_887_500);
});

it('matches later updates by provider id', function () {
    $this->apply->handle(new ProviderUpdate('gen_abc', GenerationStatus::Queued, $this->generationId));

    $this->apply->handle(($this->succeeded)(null));

    expect($this->generations->find($this->generationId)?->status())->toBe(GenerationStatus::Succeeded);
});

it('records the cost once when the same succeeded update is applied twice', function () {
    $this->apply->handle(($this->succeeded)($this->generationId));
    $savesAfterFirst = $this->generations->saves;

    $this->apply->handle(($this->succeeded)($this->generationId));

    expect($this->generations->saves)->toBe($savesAfterFirst)
        ->and($this->generations->find($this->generationId)?->cost()?->charge->nanos)->toBe(381_887_500);
});

it('reports updates for generations it does not know', function () {
    expect($this->apply->handle(new ProviderUpdate('gen_other', GenerationStatus::Queued, 'unknown-id')))->toBeFalse();
});
