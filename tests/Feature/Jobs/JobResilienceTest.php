<?php

use App\Jobs\Middleware\WaitForPixelforgeCapacity;
use App\Jobs\StoreGeneratedImageJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation as GenerationRecord;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

describe('waiting for Pixelforge capacity', function () {
    beforeEach(function () {
        $this->gate = new WaitForPixelforgeCapacity(3);
        $this->job = (new SubmitGenerationJob('0193a5c2-0000-7000-8000-000000000001'))->withFakeQueueInteractions();
        $this->ran = false;
        $this->next = function () {
            $this->ran = true;
        };
    });

    it('waits without calling Pixelforge while 3 generations are already running', function () {
        GenerationRecord::factory()->processing()->count(2)->create();
        GenerationRecord::factory()->queued()->create();

        $this->gate->handle($this->job, $this->next);

        expect($this->ran)->toBeFalse();
        $this->job->assertReleased(delay: WaitForPixelforgeCapacity::RECHECK_AFTER_SECONDS);
    });

    it('submits when there is room', function () {
        GenerationRecord::factory()->processing()->count(2)->create();
        GenerationRecord::factory()->succeeded()->count(3)->create();

        $this->gate->handle($this->job, $this->next);

        expect($this->ran)->toBeTrue();
        $this->job->assertNotReleased();
    });

    it('does not let generations stuck in flight in our records block submissions forever', function () {
        GenerationRecord::factory()->processing()->count(3)->create(['updated_at' => now()->subMinutes(10)]);

        $this->gate->handle($this->job, $this->next);

        expect($this->ran)->toBeTrue();
    });
});

it('copies images on the low-priority queue, so they never hold up submissions', function () {
    Queue::fake();

    StoreGeneratedImageJob::dispatch('0193a5c2-0000-7000-8000-000000000001');

    Queue::assertPushedOn(StoreGeneratedImageJob::QUEUE, StoreGeneratedImageJob::class);
});

it('runs the poller with an overlap lock that expires in minutes, not a day', function () {
    $poll = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'pixelforge:poll'));
    $prune = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'queue:prune-failed'));

    expect($poll)->not->toBeNull()
        ->and($poll->withoutOverlapping)->toBeTrue()
        ->and($poll->expiresAt)->toBe(5)
        ->and($prune)->not->toBeNull();
});

it('still acknowledges a webhook when its job cannot be queued, leaving the event for the sweep', function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    $record = GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down')));

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeFixture('generation-succeeded', [
        'metadata' => ['generation_id' => $record->id],
    ])))->assertNoContent();

    expect(DB::table('provider_events')->where('id', 'evt_1')->value('processed_at'))->toBeNull()
        ->and(DB::table('provider_events')->where('id', 'evt_1')->exists())->toBeTrue();
});
