<?php

use App\Domain\Billing\PricingSchedule;
use App\Domain\Billing\TokenRange;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;

it('estimates text tokens as ceil(unicode characters / 4)', function (string $prompt, int $expected) {
    $request = new GenerationRequest($prompt, ImageSize::Square, Quality::Low);

    expect(pixelforgeDocsPricing()->estimatedTextTokens($request))->toBe($expected);
})->with([
    '400 characters' => [str_repeat('a', 400), 100],
    '37 characters' => [str_repeat('a', 37), 10],
    'one character' => ['a', 1],
    'multibyte counts characters, not bytes (23 chars, 31 bytes)' => ['Café ☕ at dusk — 🌆 neon', 6],
]);

it('widens the text token range to whole tokens around the ±15% variance', function (int $characters, int $minimum, int $maximum) {
    $request = new GenerationRequest(str_repeat('a', $characters), ImageSize::Square, Quality::Low);

    $range = pixelforgeDocsPricing()->textTokenRange($request);

    expect([$range->minimum, $range->maximum])->toBe([$minimum, $maximum]);
})->with([
    'estimate 100 (docs example)' => [400, 85, 115],
    'estimate 7: 5.95..8.05' => [28, 5, 9],
    'estimate 2: 1.7..2.3' => [7, 1, 3],
    'estimate 1000 (4000 characters)' => [4000, 850, 1150],
    'estimate 1 (one character)' => [1, 0, 2],
]);

it('always contains every token count within ±15% of the estimate', function () {
    $pricing = pixelforgeDocsPricing();

    foreach (range(1, 1000) as $estimate) {
        $range = $pricing->textTokenRange(new GenerationRequest(str_repeat('a', $estimate * 4), ImageSize::Square, Quality::Low));

        foreach (range(0, 2 * $estimate) as $tokens) {
            $withinVariance = abs($tokens - $estimate) * 100 <= 15 * $estimate;

            if ($withinVariance && ($tokens < $range->minimum || $tokens > $range->maximum)) {
                $this->fail("Estimate {$estimate}: {$tokens} tokens is within ±15% but outside {$range->minimum}..{$range->maximum}.");
            }
        }
    }

    expect(true)->toBeTrue();
});

it('uses the exact output tokens for each quality and size', function (Quality $quality, ImageSize $size, int $expected) {
    expect(pixelforgeDocsPricing()->outputTokensFor($quality, $size))->toBe($expected);
})->with([
    [Quality::Low, ImageSize::Square, 280],
    [Quality::Low, ImageSize::Landscape, 420],
    [Quality::Low, ImageSize::Portrait, 420],
    [Quality::Medium, ImageSize::Square, 1100],
    [Quality::Medium, ImageSize::Landscape, 1650],
    [Quality::Medium, ImageSize::Portrait, 1650],
    [Quality::High, ImageSize::Square, 4300],
    [Quality::High, ImageSize::Landscape, 6450],
    [Quality::High, ImageSize::Portrait, 6450],
]);

it('bills 765 input image tokens per reference image', function () {
    $request = new GenerationRequest('a lighthouse', ImageSize::Square, Quality::Low, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]);

    $pricing = pixelforgeDocsPricing();

    expect($pricing->minimumUsageFor($request)->inputImageTokens)->toBe(1530)
        ->and($pricing->maximumUsageFor($request)->inputImageTokens)->toBe(1530);
});

it('rejects a schedule missing a quality and size combination', function () {
    new PricingSchedule(
        rates: pixelforgeDocsRates(),
        charactersPerTextToken: 4,
        textTokenVarianceBasisPoints: 1500,
        tokensPerReferenceImage: 765,
        outputTokens: ['low' => ['1024x1024' => 280]],
        reasoningTokens: [
            'low' => new TokenRange(0, 500),
            'medium' => new TokenRange(200, 2000),
            'high' => new TokenRange(1000, 8000),
        ],
    );
})->throws(InvalidArgumentException::class);
