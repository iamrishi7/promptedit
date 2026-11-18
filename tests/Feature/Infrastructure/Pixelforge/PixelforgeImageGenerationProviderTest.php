<?php

use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.key' => 'pf_test_key',
    ]);
    Http::preventStrayRequests();

    $this->provider = app(ImageGenerationProvider::class);
    $this->request = new GenerationRequest('A lighthouse on a cliff at dawn, oil painting', ImageSize::Landscape, Quality::High, ['https://example.com/style.png']);
    $this->generationId = '019a8d3e-0000-7000-8000-000000000001';
});

it('submits only documented parameters with the generation id as idempotency key and metadata', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued'), 202)]);

    $update = $this->provider->submit($this->generationId, $this->request);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', $this->generationId)
        && $request->data() === [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'metadata' => ['generation_id' => $this->generationId],
            'reference_images' => ['https://example.com/style.png'],
        ]);
    expect($update->providerId)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($update->status)->toBe(GenerationStatus::Queued)
        ->and($update->generationId)->toBe($this->generationId);
});

it('omits reference images when there are none', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued'), 202)]);

    $this->provider->submit($this->generationId, new GenerationRequest('A lighthouse', ImageSize::Square, Quality::Low));

    Http::assertSent(fn (Request $request) => ! array_key_exists('reference_images', $request->data()));
});

it('maps temporary provider errors to retry later, honouring Retry-After', function (int $status, string $code, array $headers, ?int $retryAfter, bool $outcomeUnknown) {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['type' => 'rate_limit_error', 'code' => $code, 'message' => 'Slow down.']], $status, $headers)]);

    try {
        $this->provider->submit($this->generationId, $this->request);
        $this->fail('Expected ProviderUnavailable.');
    } catch (ProviderUnavailable $unavailable) {
        expect($unavailable->reason)->toBe($code)
            ->and($unavailable->retryAfterSeconds)->toBe($retryAfter)
            ->and($unavailable->outcomeUnknown)->toBe($outcomeUnknown);
    }
})->with([
    'rate limit: nothing created' => [429, 'rate_limit_exceeded', ['Retry-After' => '7'], 7, false],
    'concurrency limit: nothing created' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], 5, false],
    'overloaded: nothing created' => [503, 'overloaded', ['Retry-After' => '2'], 2, false],
    'server error: outcome unknown' => [500, 'internal_error', [], null, true],
]);

it('treats a network failure as retry later with an unknown outcome', function () {
    Http::fake(['pixelforge.test/v1/generations' => fn () => throw new ConnectionException('timed out')]);

    try {
        $this->provider->submit($this->generationId, $this->request);
        $this->fail('Expected ProviderUnavailable.');
    } catch (ProviderUnavailable $unavailable) {
        expect($unavailable->outcomeUnknown)->toBeTrue();
    }
});

it('maps definitive refusals to a free failure with a clear reason', function (int $status, string $code, string $expectedFailureCode) {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => 'The prompt was rejected.']], $status)]);

    try {
        $this->provider->submit($this->generationId, $this->request);
        $this->fail('Expected ProviderRefused.');
    } catch (ProviderRefused $refused) {
        expect($refused->failure->code)->toBe($expectedFailureCode)
            ->and($refused->failure->message)->not->toBeEmpty();
    }
})->with([
    'content policy (400)' => [400, 'content_policy_violation', 'content_policy_violation'],
    'daily quota (429)' => [429, 'daily_limit_exceeded', 'daily_limit_exceeded'],
    'validation (422)' => [422, 'validation_failed', GenerationFailure::PROVIDER_REJECTED_REQUEST],
    'idempotency key reused (409)' => [409, 'idempotency_key_reused', GenerationFailure::PROVIDER_REJECTED_REQUEST],
    'bad api key (401)' => [401, 'invalid_api_key', GenerationFailure::PROVIDER_REJECTED_REQUEST],
]);

it('refuses to submit without an API key, without calling Pixelforge', function () {
    config(['services.pixelforge.key' => null]);
    Http::fake();

    expect(fn () => app(ImageGenerationProvider::class)->submit($this->generationId, $this->request))->toThrow(ProviderRefused::class);
    Http::assertNothingSent();
});

it('maps a succeeded generation to usage and rates priced exactly', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(pixelforgeFixture('generation-succeeded'))]);

    $update = $this->provider->fetch('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($update->status)->toBe(GenerationStatus::Succeeded)
        ->and($update->rates?->costOf($update->usage ?? throw new RuntimeException)->nanos)->toBe(305_510_000)
        ->and($update->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($update->completedAt?->format(DATE_ATOM))->toBe('2026-11-04T14:03:29+00:00');
});

it('maps a failed generation to its error', function () {
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(pixelforgeFixture('generation-failed'))]);

    $update = $this->provider->fetch('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($update->status)->toBe(GenerationStatus::Failed)
        ->and($update->failure?->code)->toBe('internal_error');
});

it('builds the pricing schedule from /v1/pricing and caches it', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);

    $pricing = $this->provider->pricing();
    $this->provider->pricing();

    $estimate = CostEstimate::for(new GenerationRequest(str_repeat('a', 400), ImageSize::Landscape, Quality::High, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]), $pricing, new Markup(25));

    expect($pricing->textTokenVarianceBasisPoints)->toBe(1500)
        ->and($estimate->maximumProviderCost->nanos)->toBe(373_875_000);
    Http::assertSentCount(1);
});

it('does not need an API key for pricing', function () {
    config(['services.pixelforge.key' => null]);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);

    expect(app(ImageGenerationProvider::class)->pricing()->charactersPerTextToken)->toBe(4);
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

it('finds a submission by its generation id across list pages', function () {
    Http::fake(['pixelforge.test/v1/generations?*' => fn (Request $request) => Http::response(($request->data()['cursor'] ?? null) === null
        ? ['object' => 'list', 'data' => [pixelforgeFixture('generation-queued', ['id' => 'gen_other', 'metadata' => ['generation_id' => 'someone-else'], 'created_at' => '2026-11-04T15:00:00Z'])], 'has_more' => true, 'next_cursor' => 'page2']
        : ['object' => 'list', 'data' => [pixelforgeFixture('generation-succeeded', ['metadata' => ['generation_id' => $this->generationId], 'created_at' => '2026-11-04T14:03:11Z'])], 'has_more' => false, 'next_cursor' => null])]);

    $update = $this->provider->findSubmitted($this->generationId, new DateTimeImmutable('2026-11-04T14:00:00Z'));

    expect($update?->status)->toBe(GenerationStatus::Succeeded)
        ->and($update?->generationId)->toBe($this->generationId);
    Http::assertSentCount(2);
});

it('reports a submission Pixelforge never received once the list goes back past it', function () {
    Http::fake(['pixelforge.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [
        pixelforgeFixture('generation-queued', ['id' => 'gen_newer', 'metadata' => ['generation_id' => 'x'], 'created_at' => '2026-11-04T15:00:00Z']),
        pixelforgeFixture('generation-queued', ['id' => 'gen_older', 'metadata' => ['generation_id' => 'y'], 'created_at' => '2026-11-04T13:00:00Z']),
    ], 'has_more' => true, 'next_cursor' => 'more'])]);

    expect($this->provider->findSubmitted($this->generationId, new DateTimeImmutable('2026-11-04T14:00:00Z')))->toBeNull();
    Http::assertSentCount(1);
});

it('gives up for now when the lookup cannot reach back far enough', function () {
    Http::fake(['pixelforge.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [
        pixelforgeFixture('generation-queued', ['id' => 'gen_newer', 'metadata' => ['generation_id' => 'x'], 'created_at' => '2026-11-04T15:00:00Z']),
    ], 'has_more' => true, 'next_cursor' => 'more'])]);

    expect(fn () => $this->provider->findSubmitted($this->generationId, new DateTimeImmutable('2026-11-04T14:00:00Z')))->toThrow(ProviderUnavailable::class);
    Http::assertSentCount(3);
});

it('reads daily usage exactly, as Pixelforge bills this key per UTC day', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
        ],
    ])]);

    $usage = app(ImageGenerationProvider::class)->dailyUsage('2026-11-01', '2026-11-02');

    expect($usage)->toHaveCount(2)
        ->and($usage[0]->date)->toBe('2026-11-01')
        ->and($usage[0]->generations)->toBe(3)
        ->and($usage[0]->cost->nanos)->toBe(169_080_000)
        ->and($usage[1]->cost->isZero())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-02'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

it('refuses a usage payload it cannot read exactly', function (array $day) {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [$day]])]);

    app(ImageGenerationProvider::class)->dailyUsage('2026-11-01', '2026-11-01');
})->with([
    'float cost' => [['date' => '2026-11-01', 'generations' => 1, 'cost_usd' => 0.16908]],
    'bad date' => [['date' => '01/11/2026', 'generations' => 1, 'cost_usd' => '0.169080000']],
    'missing generations' => [['date' => '2026-11-01', 'cost_usd' => '0.169080000']],
])->throws(MalformedProviderPayload::class);
