<?php

use App\Domain\Billing\PricingSchedule;
use App\Domain\Billing\TokenRange;
use App\Domain\Billing\TokenRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Pixelforge's published pricing (GET /v1/pricing, as documented) as a domain schedule.
 */
function pixelforgeDocsPricing(): PricingSchedule
{
    return new PricingSchedule(
        rates: pixelforgeDocsRates(),
        charactersPerTextToken: 4,
        textTokenVarianceBasisPoints: 1500,
        tokensPerReferenceImage: 765,
        outputTokens: [
            'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
            'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
            'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
        ],
        reasoningTokens: [
            'low' => new TokenRange(0, 500),
            'medium' => new TokenRange(200, 2000),
            'high' => new TokenRange(1000, 8000),
        ],
    );
}

function pixelforgeDocsRates(): TokenRates
{
    return TokenRates::fromDecimalStrings('5.00', '10.00', '40.00', '12.50');
}

/**
 * A Pixelforge JSON payload from tests/Fixtures/Pixelforge, optionally with top-level overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeFixture(string $name, array $overrides = []): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(__DIR__."/Fixtures/Pixelforge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return array_replace($payload, $overrides);
}

/**
 * A Pixelforge webhook event envelope wrapping a generation object.
 *
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $eventId, string $type, array $generation = []): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

/**
 * Post a webhook signed exactly like Pixelforge: hex HMAC-SHA256 of "<timestamp>.<raw body>".
 *
 * @param  array<string, mixed>  $event
 */
function postPixelforgeWebhook(array $event, ?int $timestamp = null, ?string $secret = null, ?string $body = null, ?string $signedBody = null): TestResponse
{
    $body ??= json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.($signedBody ?? $body), $secret ?? config()->string('services.pixelforge.webhook_secret'));

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'HTTP_PIXELFORGE_EVENT_ID' => (string) ($event['id'] ?? ''),
    ], content: $body);
}
