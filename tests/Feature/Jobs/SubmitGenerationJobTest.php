<?php

use App\Application\UseCases\SubmitGeneration;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation as GenerationRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.key' => 'pf_test_key',
    ]);
    Http::preventStrayRequests();

    $this->record = GenerationRecord::factory()->create();
});

it('records the Pixelforge id and status once the generation is accepted', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued', [
        'metadata' => ['generation_id' => $this->record->id],
    ]), 202)]);

    (new SubmitGenerationJob($this->record->id))->handle(app(SubmitGeneration::class));

    expect($this->record->fresh())
        ->status->toBe('queued')
        ->provider_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
});

it('releases the job for the Retry-After seconds when Pixelforge is overloaded', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '2'])]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();

    $job->handle(app(SubmitGeneration::class));

    $job->assertReleased(delay: 2);
    expect($this->record->fresh()->status)->toBe('pending');
});

it('fails the generation for free when Pixelforge rejects the prompt', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'The prompt was rejected.']], 400)]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();

    $job->handle(app(SubmitGeneration::class));

    $job->assertNotReleased();
    expect($this->record->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('content_policy_violation')
        ->charge_nanos->toBe(0);
});

it('fails the generation for free when the job gives up after Pixelforge stayed busy', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'Busy.']], 429, ['Retry-After' => '5'])]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();
    $job->handle(app(SubmitGeneration::class));

    $job->failed(new RuntimeException('Retry window passed.'));

    expect($this->record->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('submission_abandoned')
        ->charge_nanos->toBe(0);
});

it('leaves the generation pending when the job expired in a backed-up queue without ever running', function () {
    $job = new SubmitGenerationJob($this->record->id);

    $job->failed(new RuntimeException('Retry window passed before the job ran.'));

    expect($this->record->fresh())
        ->status->toBe('pending')
        ->failure_code->toBeNull();
});

it('keeps the failure provisional when an attempt timed out, so Pixelforge can still correct it', function () {
    Http::fake(['pixelforge.test/v1/generations' => fn () => throw new ConnectionException('timed out')]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();
    $job->handle(app(SubmitGeneration::class));

    $job->failed(new RuntimeException('Retry window passed.'));

    expect($this->record->fresh())
        ->status->toBe('failed')
        ->failure_code->toBe('submission_unconfirmed')
        ->charge_nanos->toBe(0);
});

it('backs off further each time Pixelforge stays busy, never sooner than its Retry-After', function (int $attempt, int $delay) {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '2'])]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    $job->handle(app(SubmitGeneration::class));

    $job->assertReleased(delay: $delay);
})->with([
    'first try: Pixelforge\'s 2 s' => [1, 2],
    'third try' => [3, 8],
    'fifth try' => [5, 32],
    'capped at a minute' => [9, 60],
]);

it('backs off exponentially when Pixelforge gives no Retry-After', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'Oops.']], 500)]);
    $job = (new SubmitGenerationJob($this->record->id))->withFakeQueueInteractions();

    $job->handle(app(SubmitGeneration::class));

    $job->assertReleased(delay: 2);
});
