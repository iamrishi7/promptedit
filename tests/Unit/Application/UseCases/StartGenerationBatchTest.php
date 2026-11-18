<?php

use App\Application\UseCases\StartGeneration;
use App\Application\UseCases\StartGenerationBatch;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Tests\Fakes\FakeImageGenerationProvider;
use Tests\Fakes\ImmediateTransactionRunner;
use Tests\Fakes\InMemoryGenerationRepository;

beforeEach(function () {
    $this->generations = new InMemoryGenerationRepository;
    $start = new StartGeneration($this->generations, new FakeImageGenerationProvider(pixelforgeDocsPricing()), new Markup(25));
    $this->batch = new StartGenerationBatch($start, new ImmediateTransactionRunner);
    $this->request = new GenerationRequest('A lighthouse', ImageSize::Square, Quality::Low);
});

it('starts one generation per image, each with its own estimate', function () {
    $started = $this->batch->handle(7, ['a', 'b', 'c'], $this->request);

    expect($started)->toHaveCount(3)
        ->and(array_unique(array_map(fn ($s) => $s->generationId, $started)))->toHaveCount(3)
        ->and(array_map(fn ($s) => $s->isNew, $started))->toBe([true, true, true])
        ->and($this->generations->estimates)->toHaveCount(3);
});

it('maps a repeated submission back to the images it already started', function () {
    $first = $this->batch->handle(7, ['a', 'b'], $this->request);
    $again = $this->batch->handle(7, ['a', 'b'], $this->request);

    expect(array_map(fn ($s) => $s->generationId, $again))->toBe(array_map(fn ($s) => $s->generationId, $first))
        ->and(array_map(fn ($s) => $s->isNew, $again))->toBe([false, false])
        ->and($this->generations->estimates)->toHaveCount(2);
});

it('refuses an empty, oversized or repeating set of ids', function (array $ids) {
    $this->batch->handle(7, $ids, $this->request);
})->with([
    'none' => [[]],
    'five' => [['a', 'b', 'c', 'd', 'e']],
    'duplicate' => [['a', 'a']],
])->throws(InvalidArgumentException::class);
