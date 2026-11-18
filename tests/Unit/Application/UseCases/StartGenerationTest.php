<?php

use App\Application\Exceptions\ClientRequestReused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\UseCases\StartGeneration;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Tests\Fakes\FakeImageGenerationProvider;
use Tests\Fakes\InMemoryGenerationRepository;

beforeEach(function () {
    $this->generations = new InMemoryGenerationRepository;
    $this->provider = new FakeImageGenerationProvider(pixelforgeDocsPricing());
    $this->start = new StartGeneration($this->generations, $this->provider, new Markup(25));
    $this->request = new GenerationRequest('A lighthouse', ImageSize::Square, Quality::Low);
});

it('starts a pending generation with its estimate and the current markup, without contacting the provider', function () {
    $started = $this->start->handle(7, 'client-a', $this->request);

    $generation = $this->generations->find($started->generationId);
    expect($started->isNew)->toBeTrue()
        ->and($generation?->status())->toBe(GenerationStatus::Pending)
        ->and($generation?->markup->percent)->toBe(25)
        ->and($this->generations->estimates)->toHaveKey($started->generationId)
        ->and($this->provider->submitted)->toBe([]);
});

it('returns the existing generation when the same form submission arrives twice', function () {
    $first = $this->start->handle(7, 'client-a', $this->request);
    $second = $this->start->handle(7, 'client-a', $this->request);

    expect($second->generationId)->toBe($first->generationId)
        ->and($second->isNew)->toBeFalse()
        ->and($this->generations->estimates)->toHaveCount(1);
});

it('starts separate generations for separate submissions and separate users', function () {
    $first = $this->start->handle(7, 'client-a', $this->request);
    $nextAttempt = $this->start->handle(7, 'client-b', $this->request);
    $otherUser = $this->start->handle(8, 'client-a', $this->request);

    expect([$first->generationId, $nextAttempt->generationId, $otherUser->generationId])->toHaveCount(3)->each->toBeString()
        ->and(array_unique([$first->generationId, $nextAttempt->generationId, $otherUser->generationId]))->toHaveCount(3)
        ->and($otherUser->isNew)->toBeTrue();
});

it('refuses a repeated client request id that carries different values', function () {
    $this->start->handle(7, 'client-a', $this->request);

    $this->start->handle(7, 'client-a', new GenerationRequest('A different lighthouse', ImageSize::Square, Quality::Low));
})->throws(ClientRequestReused::class);

it('answers a repeated submission without needing prices', function () {
    $first = $this->start->handle(7, 'client-a', $this->request);
    $this->provider->pricingFailsWith(new ProviderUnavailable('overloaded'));

    expect($this->start->handle(7, 'client-a', $this->request)->generationId)->toBe($first->generationId);
});
