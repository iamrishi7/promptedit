<?php

use App\Application\UseCases\EstimateGenerationCost;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Tests\Fakes\FakeImageGenerationProvider;

/**
 * What the form shows for a prompt of $characters characters: the total range rounded outward to cents.
 */
function shownPriceFor(int $characters, ImageSize $size, Quality $quality, int $images): string
{
    $estimate = CostEstimate::for(new GenerationRequest(str_repeat('a', $characters), $size, $quality, ['https://example.com/a.png']), pixelforgeDocsPricing(), new Markup(25));

    return $estimate->minimumCharge->times($images)->floorToCents()->toDisplayString().' – '.$estimate->maximumCharge->times($images)->ceilToCents()->toDisplayString();
}

it('says exactly which prompt lengths show the same price, checked against every length', function (ImageSize $size, Quality $quality, int $images, int $characters) {
    $estimate = new EstimateGenerationCost(new FakeImageGenerationProvider(pixelforgeDocsPricing()), new Markup(25));

    $priced = $estimate->handle(new GenerationRequest(str_repeat('a', $characters), $size, $quality, ['https://example.com/a.png']), $images);

    $shown = shownPriceFor($characters, $size, $quality, $images);
    foreach (range(1, GenerationRequest::MAX_PROMPT_CHARACTERS) as $length) {
        $inRange = $length >= $priced->samePriceFromCharacters && $length <= $priced->samePriceToCharacters;

        if ($inRange) {
            expect(shownPriceFor($length, $size, $quality, $images))->toBe($shown, "length {$length} is in range but shows another price");
        }
    }
    // The range is as wide as it can be: just outside it, the shown price changes.
    if ($priced->samePriceFromCharacters > 1) {
        expect(shownPriceFor($priced->samePriceFromCharacters - 1, $size, $quality, $images))->not->toBe($shown);
    }
    if ($priced->samePriceToCharacters < GenerationRequest::MAX_PROMPT_CHARACTERS) {
        expect(shownPriceFor($priced->samePriceToCharacters + 1, $size, $quality, $images))->not->toBe($shown);
    }
    expect($priced->samePriceFromCharacters)->toBeLessThanOrEqual($characters)
        ->and($priced->samePriceToCharacters)->toBeGreaterThanOrEqual($characters);
})->with([
    'low, square, 1 image, short' => [ImageSize::Square, Quality::Low, 1, 12],
    'high, landscape, 4 images, long' => [ImageSize::Landscape, Quality::High, 4, 2500],
    'medium, portrait, 2 images, at the limit' => [ImageSize::Portrait, Quality::Medium, 2, 4000],
]);

it('returns totals for the number of images', function () {
    $estimate = new EstimateGenerationCost(new FakeImageGenerationProvider(pixelforgeDocsPricing()), new Markup(25));
    $request = new GenerationRequest(str_repeat('a', 400), ImageSize::Landscape, Quality::High, ['https://example.com/a.png', 'https://example.com/b.png']);

    $one = $estimate->handle($request);
    $three = $estimate->handle($request, 3);

    expect($three->maximumCharge->nanos)->toBe(3 * $one->maximumCharge->nanos)
        ->and($three->minimumCharge->nanos)->toBe(3 * $one->minimumCharge->nanos)
        ->and($one->maximumCharge->nanos)->toBe(467_343_750);
});
