<?php

use App\Application\Ports\GenerationRepository;
use App\Application\UseCases\ApplyProviderUpdate;
use App\Application\UseCases\SubmitGeneration;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ProviderUpdate;
use App\Infrastructure\Pixelforge\PixelforgePayloadMapper;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.key' => 'pf_test_key',
    ]);
    Http::preventStrayRequests();

    $this->mapper = new PixelforgePayloadMapper;
    $this->succeededFor = fn (GenerationRecord $record) => $this->mapper->toProviderUpdate(pixelforgeFixture('generation-succeeded', [
        'id' => $record->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'metadata' => ['generation_id' => $record->id],
    ]));
});

it('records the cost once in the database when the same succeeded event is applied twice', function () {
    $record = GenerationRecord::factory()->queued()->create();

    app(ApplyProviderUpdate::class)->handle(($this->succeededFor)($record));
    app(ApplyProviderUpdate::class)->handle(($this->succeededFor)($record));

    expect($record->fresh())
        ->status->toBe('succeeded')
        ->provider_cost_nanos->toBe(305_510_000)
        ->charge_nanos->toBe(381_887_500);
});

it('charges the markup quoted at creation even if the configured markup changed since', function () {
    $record = GenerationRecord::factory()->queued()->create(['markup_percent' => 25]);
    config(['services.pixelforge.markup_percent' => 30]);

    app(ApplyProviderUpdate::class)->handle(($this->succeededFor)($record));

    expect($record->fresh()->charge_nanos)->toBe(381_887_500);
});

it('never lets a stale copy overwrite a recorded charge', function () {
    $record = GenerationRecord::factory()->queued()->create();
    $repository = app(GenerationRepository::class);
    $stale = $repository->find($record->id);
    app(ApplyProviderUpdate::class)->handle(($this->succeededFor)($record));

    $stale?->apply(new ProviderUpdate((string) $record->provider_id, GenerationStatus::Processing));

    expect(fn () => DB::transaction(fn () => $repository->save($stale)))->toThrow(LogicException::class)
        ->and($record->fresh())->status->toBe('succeeded')->charge_nanos->toBe(381_887_500);
});

it('records the cost when a retried submit replays a generation that already succeeded', function () {
    $record = GenerationRecord::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-succeeded', [
        'metadata' => ['generation_id' => $record->id],
    ]), 202, ['Idempotent-Replayed' => 'true'])]);

    app(SubmitGeneration::class)->handle($record->id);

    expect($record->fresh())
        ->status->toBe('succeeded')
        ->charge_nanos->toBe(381_887_500);
});

it('stores a failure whose provider message is very long, so the generation still finishes', function () {
    $record = GenerationRecord::factory()->queued()->create();

    app(ApplyProviderUpdate::class)->handle($this->mapper->toProviderUpdate(pixelforgeFixture('generation-failed', [
        'id' => $record->provider_id,
        'error' => ['code' => 'generation_failed', 'message' => str_repeat('x', 1000)],
    ])));

    expect($record->fresh())
        ->status->toBe('failed')
        ->charge_nanos->toBe(0);
});
