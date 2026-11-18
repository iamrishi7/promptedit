<?php

use App\Domain\Billing\Money;

it('parses exact dollar strings into nanodollars', function (string $dollars, int $nanos) {
    expect(Money::fromDecimalString($dollars)->nanos)->toBe($nanos);
})->with([
    'pricing rate' => ['5.00', 5_000_000_000],
    'fractional rate' => ['12.50', 12_500_000_000],
    'usage cost_usd' => ['0.169080000', 169_080_000],
    'one nanodollar' => ['0.000000001', 1],
    'whole dollars' => ['3', 3_000_000_000],
    'zero' => ['0.000000000', 0],
]);

it('rejects strings that are not exact non-negative dollar amounts', function (string $dollars) {
    Money::fromDecimalString($dollars);
})->with(['', '-1.00', '1.0000000001', 'abc', '1e3', '1,50', ' 1.00', '.5', "5.00\n"])->throws(InvalidArgumentException::class);

it('formats nanodollars with nine decimals like Pixelforge cost_usd', function (int $nanos, string $formatted) {
    expect(Money::fromNanos($nanos)->toDecimalString())->toBe($formatted);
})->with([
    [373_875_000, '0.373875000'],
    [1, '0.000000001'],
    [0, '0.000000000'],
    [12_500_000_000, '12.500000000'],
]);

it('round-trips through the decimal string format', function () {
    $money = Money::fromDecimalString('0.305510000');

    expect(Money::fromDecimalString($money->toDecimalString())->equals($money))->toBeTrue();
});

it('adds and multiplies exactly', function () {
    $money = Money::fromNanos(5_000)->times(12)->plus(Money::fromNanos(7));

    expect($money->nanos)->toBe(60_007);
});

it('rejects negative amounts', function () {
    Money::fromNanos(-1);
})->throws(InvalidArgumentException::class);

it('refuses to overflow instead of silently becoming a float', function () {
    Money::fromNanos(PHP_INT_MAX)->times(2);
})->throws(OverflowException::class);

it('refuses to overflow on addition', function () {
    Money::fromNanos(PHP_INT_MAX)->plus(Money::fromNanos(1));
})->throws(OverflowException::class);

it('rounds to whole cents outward so a displayed range always contains the exact amount', function (int $nanos, int $floor, int $ceil) {
    $money = Money::fromNanos($nanos);

    expect($money->floorToCents()->nanos)->toBe($floor)
        ->and($money->ceilToCents()->nanos)->toBe($ceil);
})->with([
    'docs worst-case charge min' => [357_781_250, 350_000_000, 360_000_000],
    'docs worst-case charge max' => [467_343_750, 460_000_000, 470_000_000],
    'exact cents stay put' => [350_000_000, 350_000_000, 350_000_000],
    'one nanodollar above a cent' => [10_000_001, 10_000_000, 20_000_000],
    'zero' => [0, 0, 0],
]);

it('displays the exact amount, trimming trailing zeros only down to cents', function (int $nanos, string $display) {
    expect(Money::fromNanos($nanos)->toDisplayString())->toBe($display);
})->with([
    'docs usage charge' => [381_887_500, '$0.3818875'],
    'whole cents' => [350_000_000, '$0.35'],
    'tenth of a dollar' => [100_000_000, '$0.10'],
    'zero' => [0, '$0.00'],
    'one nanodollar' => [1, '$0.000000001'],
    'dollars' => [12_500_000_000, '$12.50'],
]);

it('refuses to round up past the largest representable amount', function () {
    Money::fromNanos(PHP_INT_MAX)->ceilToCents();
})->throws(OverflowException::class);
