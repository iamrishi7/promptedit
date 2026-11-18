<?php

use App\Domain\Billing\TokenRates;
use App\Domain\Billing\TokenUsage;

it('computes the exact provider cost of a usage at the documented rates', function (TokenUsage $usage, int $expectedNanos) {
    expect(pixelforgeDocsRates()->costOf($usage)->nanos)->toBe($expectedNanos);
})->with([
    'docs succeeded generation ($0.305510000)' => [new TokenUsage(12, 765, 6450, 3184), 305_510_000],
    'docs usage day ($0.169080000)' => [new TokenUsage(41, 0, 3300, 2950), 169_080_000],
    'docs worked maximum ($0.373875000)' => [new TokenUsage(115, 1530, 6450, 8000), 373_875_000],
    'low quality, no reasoning' => [new TokenUsage(9, 0, 280, 0), 11_245_000],
    'nothing used' => [new TokenUsage(0, 0, 0, 0), 0],
]);

it('prices each token type at its own rate', function (TokenUsage $usage, int $expectedNanos) {
    expect(pixelforgeDocsRates()->costOf($usage)->nanos)->toBe($expectedNanos);
})->with([
    'input text at $5.00 per 1M' => [new TokenUsage(1, 0, 0, 0), 5_000],
    'input image at $10.00 per 1M' => [new TokenUsage(0, 1, 0, 0), 10_000],
    'output at $40.00 per 1M' => [new TokenUsage(0, 0, 1, 0), 40_000],
    'reasoning at $12.50 per 1M' => [new TokenUsage(0, 0, 0, 1), 12_500],
]);

it('refuses rates that would need rounding to a whole nanodollar per token', function (string $rate) {
    TokenRates::fromDecimalStrings($rate, '10.00', '40.00', '12.50');
})->with(['5.0005', '12.5002', '0.000000001'])->throws(InvalidArgumentException::class);

it('accepts rates with up to three decimals exactly', function () {
    $cost = TokenRates::fromDecimalStrings('5.001', '10.00', '40.00', '12.50')->costOf(new TokenUsage(3, 0, 0, 0));

    expect($cost->nanos)->toBe(15_003);
});

it('rejects negative token counts', function () {
    new TokenUsage(0, 0, -1, 0);
})->throws(InvalidArgumentException::class);
