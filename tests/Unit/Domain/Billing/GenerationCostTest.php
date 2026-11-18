<?php

use App\Domain\Billing\GenerationCost;
use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;

it('records the provider cost and the marked-up charge for a succeeded generation', function () {
    $cost = GenerationCost::forUsage(new TokenUsage(12, 765, 6450, 3184), pixelforgeDocsRates(), new Markup(25));

    expect($cost->providerCost->nanos)->toBe(305_510_000)
        ->and($cost->charge->nanos)->toBe(381_887_500);
});

it('costs nothing for a failed generation', function () {
    $cost = GenerationCost::free();

    expect($cost->providerCost->isZero())->toBeTrue()
        ->and($cost->charge->isZero())->toBeTrue();
});
