<?php

use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;

it('estimates the provider cost and charge range before a generation runs', function (
    string $prompt,
    ImageSize $size,
    Quality $quality,
    int $referenceImages,
    int $minimumProviderNanos,
    int $maximumProviderNanos,
    int $minimumChargeNanos,
    int $maximumChargeNanos,
) {
    $request = new GenerationRequest($prompt, $size, $quality, array_fill(0, $referenceImages, 'https://example.com/style.png'));

    $estimate = CostEstimate::for($request, pixelforgeDocsPricing(), new Markup(25));

    expect([
        $estimate->minimumProviderCost->nanos,
        $estimate->maximumProviderCost->nanos,
        $estimate->minimumCharge->nanos,
        $estimate->maximumCharge->nanos,
    ])->toBe([$minimumProviderNanos, $maximumProviderNanos, $minimumChargeNanos, $maximumChargeNanos]);
})->with([
    'docs worked maximum: high, landscape, 400 chars, 2 refs' => [str_repeat('a', 400), ImageSize::Landscape, Quality::High, 2, 286_225_000, 373_875_000, 357_781_250, 467_343_750],
    'low, square, 37 chars, no refs' => [str_repeat('a', 37), ImageSize::Square, Quality::Low, 0, 11_240_000, 17_510_000, 14_050_000, 21_887_500],
    'medium, portrait, multibyte prompt, 1 ref' => ['Café ☕ at dusk — 🌆 neon', ImageSize::Portrait, Quality::Medium, 1, 76_175_000, 98_685_000, 95_218_750, 123_356_250],
    'high, landscape, longest prompt, most refs' => [str_repeat('a', 4000), ImageSize::Landscape, Quality::High, 4, 305_350_000, 394_350_000, 381_687_500, 492_937_500],
]);

it('contains the actual cost of a documented succeeded generation', function () {
    $request = new GenerationRequest('A lighthouse on a cliff at dawn, oil painting', ImageSize::Landscape, Quality::High, ['https://example.com/style.png']);

    $estimate = CostEstimate::for($request, pixelforgeDocsPricing(), new Markup(25));

    expect($estimate->minimumProviderCost->nanos)->toBeLessThanOrEqual(305_510_000)
        ->and($estimate->maximumProviderCost->nanos)->toBeGreaterThanOrEqual(305_510_000);
});
