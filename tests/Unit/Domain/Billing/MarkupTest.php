<?php

use App\Domain\Billing\Markup;
use App\Domain\Billing\Money;

it('charges the provider cost plus 25 percent, rounded half up to the nanodollar', function (int $providerNanos, int $chargeNanos) {
    expect((new Markup(25))->chargeFor(Money::fromNanos($providerNanos))->nanos)->toBe($chargeNanos);
})->with([
    'docs succeeded generation' => [305_510_000, 381_887_500],
    'docs worked maximum' => [373_875_000, 467_343_750],
    'divisible by 4' => [100, 125],
    '1.25 rounds down' => [1, 1],
    '2.5 rounds up' => [2, 3],
    '3.75 rounds up' => [3, 4],
    'realistic amount, .25 rounds down' => [305_510_001, 381_887_501],
    'realistic amount, .5 rounds up' => [305_510_002, 381_887_503],
    'free' => [0, 0],
]);

it('refuses to overflow on an absurd provider cost', function () {
    (new Markup(25))->chargeFor(Money::fromNanos(PHP_INT_MAX));
})->throws(OverflowException::class);

it('charges exactly the provider cost with no markup', function () {
    expect((new Markup(0))->chargeFor(Money::fromNanos(305_510_000))->nanos)->toBe(305_510_000);
});

it('rejects a negative markup', function () {
    new Markup(-5);
})->throws(InvalidArgumentException::class);
