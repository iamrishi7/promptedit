<?php

use App\Application\UseCases\ProcessProviderEvent;
use App\Jobs\ProcessProviderEventJob;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);

    $this->record = GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $this->event = fn (string $eventId, string $type, string $fixture = 'generation-succeeded') => pixelforgeEvent($eventId, $type, pixelforgeFixture($fixture, [
        'metadata' => ['generation_id' => $this->record->id],
    ]));
});

it('applies a succeeded event and records the cost', function () {
    postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'))->assertNoContent();

    expect($this->record->fresh())
        ->status->toBe('succeeded')
        ->provider_cost_nanos->toBe(305_510_000)
        ->charge_nanos->toBe(381_887_500)
        ->and(DB::table('provider_events')->where('id', 'evt_1')->value('outcome'))->toBe('applied');
});

it('records a duplicate delivery once and charges once', function () {
    $event = ($this->event)('evt_1', 'generation.succeeded');

    postPixelforgeWebhook($event)->assertNoContent();
    postPixelforgeWebhook($event)->assertNoContent();

    expect(DB::table('provider_events')->count())->toBe(1)
        ->and($this->record->fresh()->charge_nanos)->toBe(381_887_500);
});

it('ignores a processing event that arrives after succeeded', function () {
    postPixelforgeWebhook(($this->event)('evt_2', 'generation.succeeded'))->assertNoContent();

    postPixelforgeWebhook(($this->event)('evt_1', 'generation.processing', 'generation-queued'))->assertNoContent();

    expect($this->record->fresh())
        ->status->toBe('succeeded')
        ->charge_nanos->toBe(381_887_500);
});

it('matches an event that arrives before the submit response by the generation id in metadata', function () {
    $pending = GenerationRecord::factory()->create();
    $event = pixelforgeEvent('evt_1', 'generation.processing', pixelforgeFixture('generation-queued', [
        'id' => 'gen_new',
        'status' => 'processing',
        'metadata' => ['generation_id' => $pending->id],
    ]));

    postPixelforgeWebhook($event)->assertNoContent();

    expect($pending->fresh())
        ->status->toBe('processing')
        ->provider_id->toBe('gen_new');
});

it('marks a failed generation as free', function () {
    postPixelforgeWebhook(($this->event)('evt_1', 'generation.failed', 'generation-failed'))->assertNoContent();

    expect($this->record->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('internal_error')
        ->charge_nanos->toBe(0);
});

it('acknowledges events it does not act on', function (array $event, string $outcome) {
    postPixelforgeWebhook($event)->assertNoContent();

    expect(DB::table('provider_events')->where('id', $event['id'])->value('outcome'))->toBe($outcome);
})->with([
    'dashboard ping' => [pixelforgeEvent('evt_ping', 'ping'), 'ignored'],
    'unhandled event type' => [pixelforgeEvent('evt_other', 'invoice.paid'), 'ignored'],
    'generation from another environment' => [pixelforgeEvent('evt_elsewhere', 'generation.processing', pixelforgeFixture('generation-queued', [
        'id' => 'gen_elsewhere', 'status' => 'processing', 'metadata' => ['generation_id' => '019a0000-0000-7000-8000-000000000999'],
    ])), 'unknown_generation'],
]);

it('rejects requests that are not signed by Pixelforge, changing nothing', function (Closure $send) {
    $send->call($this)->assertStatus(400);

    expect(DB::table('provider_events')->count())->toBe(0)
        ->and($this->record->fresh()->status)->toBe('queued');
})->with([
    'wrong secret' => [fn () => postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'), secret: 'whsec_attacker')],
    'stale timestamp (replay)' => [fn () => postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'), timestamp: now()->getTimestamp() - 301)],
    'tampered body' => [fn () => postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'), signedBody: '{"id":"evt_1","type":"ping"}')],
    'missing signature' => [fn () => $this->postJson(route('webhooks.pixelforge'), ($this->event)('evt_1', 'generation.succeeded'))],
]);

it('verifies the signature against the raw body, not re-encoded JSON', function () {
    $event = ($this->event)('evt_1', 'generation.succeeded');

    postPixelforgeWebhook($event, body: json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->assertNoContent();

    expect($this->record->fresh()->status)->toBe('succeeded');
});

it('rejects a signed body that is not an event', function () {
    postPixelforgeWebhook([], body: '"not an event"')->assertStatus(400);
});

it('fails loudly when the signing secret is not configured, so Pixelforge retries later', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'), secret: 'whsec_test')->assertStatus(500);
});

it('keeps a recorded event until it is applied, and queues it again on redelivery', function () {
    Queue::fake();
    $event = ($this->event)('evt_1', 'generation.succeeded');

    postPixelforgeWebhook($event)->assertNoContent();
    postPixelforgeWebhook($event)->assertNoContent();

    Queue::assertPushed(ProcessProviderEventJob::class, 2);
    expect($this->record->fresh()->status)->toBe('queued')
        ->and(DB::table('provider_events')->where('id', 'evt_1')->value('processed_at'))->toBeNull();

    app(ProcessProviderEvent::class)->handle('evt_1');
    app(ProcessProviderEvent::class)->handle('evt_1');

    expect($this->record->fresh()->charge_nanos)->toBe(381_887_500);
});

it('does not queue already-processed duplicates', function () {
    $event = ($this->event)('evt_1', 'generation.succeeded');
    postPixelforgeWebhook($event)->assertNoContent();
    Queue::fake();

    postPixelforgeWebhook($event)->assertNoContent();

    Queue::assertNothingPushed();
});

it('keeps the charge when a failed event arrives after succeeded', function () {
    postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'))->assertNoContent();

    postPixelforgeWebhook(($this->event)('evt_2', 'generation.failed', 'generation-failed'))->assertNoContent();

    expect($this->record->fresh())
        ->status->toBe('succeeded')
        ->charge_nanos->toBe(381_887_500);
});

it('marks a signed but unreadable event as malformed and leaves the generation for the poller', function (array $overrides) {
    $event = pixelforgeEvent('evt_bad', 'generation.succeeded', pixelforgeFixture('generation-succeeded', [
        'metadata' => ['generation_id' => $this->record->id],
        ...$overrides,
    ]));

    postPixelforgeWebhook($event)->assertNoContent();

    expect(DB::table('provider_events')->where('id', 'evt_bad')->value('outcome'))->toBe('malformed')
        ->and($this->record->fresh())->status->toBe('queued')->charge_nanos->toBeNull();
})->with([
    'succeeded without completed_at' => [['completed_at' => null]],
    'unknown status' => [['status' => 'canceled']],
    'failed without error message' => [['status' => 'failed', 'usage' => null, 'error' => ['code' => 'internal_error']]],
    'non-integer token count' => [['usage' => ['input_text_tokens' => '12', 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184]]],
]);

it('marks an event naming a different Pixelforge generation as a conflict', function () {
    $event = pixelforgeEvent('evt_conflict', 'generation.succeeded', pixelforgeFixture('generation-succeeded', [
        'id' => 'gen_someone_else',
        'metadata' => ['generation_id' => $this->record->id],
    ]));

    postPixelforgeWebhook($event)->assertNoContent();

    expect(DB::table('provider_events')->where('id', 'evt_conflict')->value('outcome'))->toBe('conflict')
        ->and($this->record->fresh()->status)->toBe('queued');
});

it('logs why a webhook was rejected, without the secret', function () {
    Log::spy();

    postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'), secret: 'whsec_other')->assertStatus(400);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Pixelforge webhook rejected'
        && str_contains($context['reason'], 'signature mismatch')
        && $context['event_id'] === 'evt_1'
        && ! str_contains(json_encode($context), 'whsec_'));
});

it('refuses oversized bodies before hashing them', function () {
    postPixelforgeWebhook(['id' => 'evt_big', 'type' => 'ping', 'padding' => str_repeat('x', 70_000)])->assertStatus(413);

    expect(DB::table('provider_events')->count())->toBe(0);
});

it('keeps accepting webhooks during maintenance mode', function () {
    $this->app->maintenanceMode()->activate([]);

    try {
        postPixelforgeWebhook(($this->event)('evt_1', 'generation.succeeded'))->assertNoContent();
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }

    expect($this->record->fresh()->status)->toBe('succeeded');
});
