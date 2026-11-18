<?php

use App\Domain\Billing\RoundingPolicy;

it('rounds half away from zero', function (int $dividend, int $divisor, int $expected) {
    expect(RoundingPolicy::divideHalfUp($dividend, $divisor))->toBe($expected);
})->with([
    'exact' => [8, 4, 2],
    'below half' => [5, 4, 1],
    'exactly half' => [6, 4, 2],
    'above half' => [7, 4, 2],
    'half of an odd nanodollar' => [5, 2, 3],
    'negative half' => [-5, 2, -3],
    'zero' => [0, 100, 0],
]);

it('rounds down to the next lower integer', function (int $dividend, int $divisor, int $expected) {
    expect(RoundingPolicy::divideFloor($dividend, $divisor))->toBe($expected);
})->with([
    'exact' => [850_000, 10_000, 85],
    'fraction' => [59_500, 10_000, 5],
    'negative fraction' => [-5, 2, -3],
]);

it('rounds up to the next higher integer', function (int $dividend, int $divisor, int $expected) {
    expect(RoundingPolicy::divideCeil($dividend, $divisor))->toBe($expected);
})->with([
    'exact' => [400, 4, 100],
    'fraction' => [37, 4, 10],
    'smallest fraction' => [1, 4, 1],
    'negative fraction' => [-5, 2, -2],
    'zero' => [0, 4, 0],
]);

it('rejects a divisor that is not positive', function (int $divisor) {
    RoundingPolicy::divideHalfUp(10, $divisor);
})->with([0, -2])->throws(InvalidArgumentException::class);
