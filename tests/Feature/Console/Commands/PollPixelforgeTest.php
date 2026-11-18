<?php

use App\Jobs\StoreGeneratedImageJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.key' => 'pf_test_key',
    ]);
    Http::preventStrayRequests();
    $this->freezeTime();
    // Image copies have their own tests; here only whether the poller queues them matters.
    Queue::fake([StoreGeneratedImageJob::class]);

    $this->stale = fn (array $attributes = []) => GenerationRecord::factory()->processing()->create([
        'updated_at' => now()->subMinutes(5),
        ...$attributes,
    ]);
    $this->snapshotFor = fn (GenerationRecord $record, string $fixture = 'generation-succeeded') => pixelforgeFixture($fixture, [
        'id' => $record->provider_id,
        'metadata' => ['generation_id' => $record->id],
    ]);
});

it('refreshes an in-flight generation that missed its webhook', function () {
    $record = ($this->stale)();
    Http::fake(["pixelforge.test/v1/generations/{$record->provider_id}" => Http::response(($this->snapshotFor)($record))]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($record->fresh())
        ->status->toBe('succeeded')
        ->charge_nanos->toBe(381_887_500)
        ->last_polled_at->not->toBeNull();
});

it('leaves generations alone that were updated or checked recently', function (array $attributes) {
    Http::fake();
    GenerationRecord::factory()->processing()->create($attributes);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'updated 10 seconds ago' => [['updated_at' => now()->subSeconds(10)]],
    'checked 10 seconds ago' => [['updated_at' => now()->subMinutes(5), 'last_polled_at' => now()->subSeconds(10)]],
]);

it('stops calling Pixelforge for this run when it answers busy', function () {
    $first = ($this->stale)(['last_polled_at' => now()->subMinutes(3)]);
    $second = ($this->stale)(['last_polled_at' => now()->subMinutes(2)]);
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '30'])]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    Http::assertSentCount(1);
    expect($first->fresh()->status)->toBe('processing')
        ->and($second->fresh()->last_polled_at?->toIso8601String())->toBe(now()->subMinutes(2)->toIso8601String());
});

it('keeps going when one generation cannot be refreshed', function () {
    $missing = ($this->stale)(['last_polled_at' => now()->subMinutes(3)]);
    $ok = ($this->stale)(['last_polled_at' => now()->subMinutes(2)]);
    Http::fake([
        "pixelforge.test/v1/generations/{$missing->provider_id}" => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404),
        "pixelforge.test/v1/generations/{$ok->provider_id}" => Http::response(($this->snapshotFor)($ok)),
    ]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($missing->fresh()->status)->toBe('processing')
        ->and($ok->fresh()->status)->toBe('succeeded');
});

it('stays within its per-minute request budget', function () {
    config(['services.pixelforge.poll_requests_per_minute' => 1]);
    ($this->stale)();
    ($this->stale)();
    Http::fake(['pixelforge.test/v1/generations/*' => fn ($request) => Http::response(pixelforgeFixture('generation-queued', ['status' => 'processing']))]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    Http::assertSentCount(1);
});

it('bills an unconfirmed submission that Pixelforge did receive', function () {
    $record = GenerationRecord::factory()->create([
        'status' => 'failed', 'provider_cost_nanos' => 0, 'charge_nanos' => 0, 'submission_outcome_unknown' => true,
        'failure_code' => 'submission_unconfirmed', 'failure_message' => 'Checking.', 'created_at' => now()->subMinutes(30),
    ]);
    Http::fake(['pixelforge.test/v1/generations?*' => Http::response(['object' => 'list', 'has_more' => false, 'next_cursor' => null, 'data' => [
        pixelforgeFixture('generation-succeeded', ['metadata' => ['generation_id' => $record->id], 'created_at' => now()->subMinutes(29)->toIso8601ZuluString()]),
    ]])]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($record->fresh())
        ->status->toBe('succeeded')
        ->failure_code->toBeNull()
        ->charge_nanos->toBe(381_887_500);
});

it('confirms an unconfirmed submission as never started, and free, when Pixelforge has no record of it', function () {
    $record = GenerationRecord::factory()->create([
        'status' => 'failed', 'provider_cost_nanos' => 0, 'charge_nanos' => 0, 'submission_outcome_unknown' => true,
        'failure_code' => 'submission_unconfirmed', 'failure_message' => 'Checking.', 'created_at' => now()->subMinutes(30),
    ]);
    Http::fake(['pixelforge.test/v1/generations?*' => Http::response(['object' => 'list', 'has_more' => true, 'next_cursor' => 'x', 'data' => [
        pixelforgeFixture('generation-queued', ['id' => 'gen_old', 'metadata' => ['generation_id' => 'other'], 'created_at' => now()->subHours(2)->toIso8601ZuluString()]),
    ]])]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($record->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('submission_not_found')
        ->charge_nanos->toBe(0);
});

it('re-queues stuck submissions and abandons ones stuck far too long', function () {
    Queue::fake();
    $fresh = GenerationRecord::factory()->create(['created_at' => now()->subMinute()]);
    $stuck = GenerationRecord::factory()->create(['created_at' => now()->subMinutes(30)]);
    $abandoned = GenerationRecord::factory()->create(['created_at' => now()->subMinutes(45)]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $stuck->id);
    Queue::assertNotPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => in_array($job->generationId, [$fresh->id, $abandoned->id], true));
    // No attempt ever reached Pixelforge, so it says "never sent", not "Pixelforge stayed busy".
    expect($abandoned->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('submission_not_sent')
        ->charge_nanos->toBe(0);
});

it('says Pixelforge stayed busy when it answered busy before the submission was abandoned', function () {
    Queue::fake();
    $busy = GenerationRecord::factory()->create(['created_at' => now()->subMinutes(45), 'submission_attempts' => 3, 'busy_refusals' => 3]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($busy->fresh()->failure_code)->toBe('submission_abandoned');
});

it('abandons as unconfirmed when an attempt began but its answer was never recorded, so a later success still bills', function () {
    Queue::fake();
    $lost = GenerationRecord::factory()->create(['created_at' => now()->subMinutes(45), 'submission_attempts' => 1]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($lost->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('submission_unconfirmed');
});

it('queues a copy of every succeeded image that still needs one and can still be fetched', function () {
    $unstored = GenerationRecord::factory()->succeeded()->create(['updated_at' => now()]);
    GenerationRecord::factory()->succeeded()->create(['updated_at' => now(), 'stored_image_path' => 'generations/1/x.png']);
    GenerationRecord::factory()->succeeded()->create(['updated_at' => now(), 'image_unstorable' => true]);
    GenerationRecord::factory()->succeeded()->count(60)->create(['updated_at' => now(), 'image_url_expires_at' => now()->subMinute()]);
    GenerationRecord::factory()->processing()->create(['updated_at' => now()]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    // Expired, unstorable and already-stored images never crowd out one that can still be saved.
    Queue::assertPushed(StoreGeneratedImageJob::class, 1);
    Queue::assertPushed(StoreGeneratedImageJob::class, fn (StoreGeneratedImageJob $job) => $job->generationId === $unstored->id);
});

it('applies webhook events that were recorded but never processed', function () {
    $record = GenerationRecord::factory()->queued()->create();
    DB::table('provider_events')->insert([
        'id' => 'evt_stuck',
        'type' => 'generation.succeeded',
        'payload' => json_encode(pixelforgeEvent('evt_stuck', 'generation.succeeded', ($this->snapshotFor)($record))),
        'received_at' => now()->subMinutes(15),
    ]);

    $this->artisan('pixelforge:poll')->assertSuccessful();

    expect($record->fresh()->charge_nanos)->toBe(381_887_500)
        ->and(DB::table('provider_events')->where('id', 'evt_stuck')->value('outcome'))->toBe('applied');
});
