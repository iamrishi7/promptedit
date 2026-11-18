<?php

use App\Infrastructure\Pixelforge\PixelforgeWebhookSignature;

beforeEach(function () {
    $this->signature = new PixelforgeWebhookSignature('whsec_test');
    $this->body = '{"id":"evt_1","type":"ping"}';
    $this->now = 1_762_265_009;
    $this->sign = fn (int $timestamp, string $body = '{"id":"evt_1","type":"ping"}', string $secret = 'whsec_test') => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
});

it('accepts a signature over the exact raw body', function () {
    expect($this->signature->isValid($this->body, ($this->sign)($this->now), $this->now))->toBeTrue();
});

it('accepts timestamps up to 300 seconds old or ahead', function (int $offset) {
    expect($this->signature->isValid($this->body, ($this->sign)($this->now + $offset), $this->now))->toBeTrue();
})->with([-300, 300]);

it('rejects replays and tampering', function (Closure $header) {
    expect($this->signature->isValid($this->body, $header->call($this), $this->now))->toBeFalse();
})->with([
    'older than 300 seconds' => [fn () => ($this->sign)($this->now - 301)],
    'more than 300 seconds ahead' => [fn () => ($this->sign)($this->now + 301)],
    'different body' => [fn () => ($this->sign)($this->now, '{"id":"evt_1","type":"generation.succeeded"}')],
    'wrong secret' => [fn () => ($this->sign)($this->now, secret: 'whsec_other')],
    'no signature' => [fn () => "t={$this->now}"],
    'no timestamp' => [fn () => 'v1='.hash_hmac('sha256', "{$this->now}.{$this->body}", 'whsec_test')],
    'non-numeric timestamp' => [fn () => 't=abc,v1=00'],
    'empty header' => [fn () => ''],
]);

it('accepts the header when one of several signatures matches', function () {
    $header = ($this->sign)($this->now).',v1=deadbeef';

    expect($this->signature->isValid($this->body, $header, $this->now))->toBeTrue();
});

it('rejects everything when no secret is configured', function () {
    $signature = new PixelforgeWebhookSignature(null);

    expect($signature->isConfigured())->toBeFalse()
        ->and($signature->isValid($this->body, ($this->sign)($this->now), $this->now))->toBeFalse();
});
