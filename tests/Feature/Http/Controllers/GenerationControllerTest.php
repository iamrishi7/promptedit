<?php

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->pricingResponse = Http::response(pixelforgeFixture('pricing'));
    Http::fake(['*/v1/pricing' => fn () => $this->pricingResponse]);
    Queue::fake();

    $this->user = User::factory()->create();

    // The docs' worst case: high quality, 1536x1024, 400 characters, 2 reference images.
    $this->form = [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_image_urls' => ['https://example.com/a.png', 'https://example.com/b.png'],
        'client_request_id' => '0193a5c2-0000-7000-8000-00000000000a',
    ];
});

describe('estimate', function () {
    it('prices the request with the same formula used when it is started', function () {
        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), $this->form)
            ->assertOk()
            ->assertExactJson([
                'count' => 1,
                'min_nanos' => '357781250',
                'max_nanos' => '467343750',
                'min_display' => '$0.35',
                'max_display' => '$0.47',
                // "$0.35 – $0.47" is what any prompt of 1–1876 characters would show with these settings.
                'same_price_for_characters' => ['from' => 1, 'to' => 1876],
            ]);
    });

    it('rejects requests Pixelforge would reject, in plain language', function (array $override, string $field) {
        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), [...$this->form, ...$override])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'empty prompt' => [['prompt' => '   '], 'prompt'],
        'prompt over 4000 unicode characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
        'unknown size' => [['size' => '512x512'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'five reference images' => [['reference_image_urls' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_image_urls'],
        'non-http reference image' => [['reference_image_urls' => ['ftp://example.com/a.png']], 'reference_image_urls.0'],
    ]);

    it('accepts a 4000-character multibyte prompt', function () {
        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), [...$this->form, 'prompt' => str_repeat('é', 4000)])
            ->assertOk();
    });

    it('says so when Pixelforge pricing is unavailable instead of guessing a price', function () {
        $this->pricingResponse = Http::response(['error' => ['code' => 'overloaded']], 503);

        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), $this->form)
            ->assertServiceUnavailable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Try again'));
    });

    it('is only available to signed-in users', function () {
        $this->postJson(route('generations.estimate'), $this->form)->assertUnauthorized();
    });
});

describe('estimate for several images', function () {
    it('prices every image: each is its own generation with the same estimate', function () {
        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), [...$this->form, 'count' => 3])
            ->assertOk()
            ->assertExactJson([
                'count' => 3,
                'min_nanos' => '1073343750',
                'max_nanos' => '1402031250',
                'min_display' => '$1.07',
                'max_display' => '$1.41',
                'same_price_for_characters' => ['from' => 21, 'to' => 1876],
            ]);
    });

    it('allows one to four images', function (int $count) {
        $this->actingAs($this->user)
            ->postJson(route('generations.estimate'), [...$this->form, 'count' => $count])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');
    })->with([0, 5]);
});

describe('store', function () {
    it('starts one generation per requested image and queues each once, even when submitted twice', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), [...$this->form, 'count' => 3])
            ->assertRedirect(route('generations.create'));
        $this->actingAs($this->user)
            ->post(route('generations.store'), [...$this->form, 'count' => 3])
            ->assertRedirect(route('generations.create'));

        expect(Generation::query()->count())->toBe(3)
            ->and(Generation::query()->pluck('client_request_id')->unique())->toHaveCount(3)
            ->and(Generation::query()->where('client_request_id', $this->form['client_request_id'])->exists())->toBeTrue();
        Queue::assertPushed(SubmitGenerationJob::class, 3);
    });

    it('starts none of the images when pricing is unavailable', function () {
        $this->pricingResponse = Http::response(['error' => ['code' => 'overloaded']], 503);
        cache()->flush();

        $this->actingAs($this->user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), [...$this->form, 'count' => 4])
            ->assertSessionHasErrors('form');

        expect(Generation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('saves a pending generation with its estimate, queues the submission and returns to the studio', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->form)
            ->assertRedirect(route('generations.create'));

        $generation = Generation::query()->sole();
        expect($generation->user_id)->toBe($this->user->id)
            ->and($generation->status)->toBe('pending')
            ->and($generation->markup_percent)->toBe(25)
            ->and($generation->estimated_min_charge_nanos)->toBe(357_781_250)
            ->and($generation->estimated_max_charge_nanos)->toBe(467_343_750)
            ->and($generation->reference_image_urls)->toBe($this->form['reference_image_urls']);

        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $generation->id);
    });

    it('starts and submits only one generation when the same form is submitted twice', function () {
        $this->actingAs($this->user)->post(route('generations.store'), $this->form)->assertRedirect(route('generations.create'));
        $this->actingAs($this->user)->post(route('generations.store'), $this->form)->assertRedirect(route('generations.create'));

        expect(Generation::query()->count())->toBe(1);
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    });

    it('refuses to reuse a submitted form for different values instead of showing the old generation', function () {
        $this->actingAs($this->user)->post(route('generations.store'), $this->form);

        $this->actingAs($this->user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), [...$this->form, 'prompt' => 'Something else entirely'])
            ->assertRedirect(route('generations.create'))
            ->assertSessionHasErrors('form');

        expect(Generation::query()->count())->toBe(1);
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    });

    it('confirms a repeated submission even while prices are unavailable', function () {
        $this->actingAs($this->user)->post(route('generations.store'), $this->form);
        $this->pricingResponse = Http::response(['error' => ['code' => 'overloaded']], 503);
        cache()->flush();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->form)
            ->assertRedirect(route('generations.create'))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SubmitGenerationJob::class, 1);
    });

    it('starts a new generation for a new form attempt', function () {
        $this->actingAs($this->user)->post(route('generations.store'), $this->form);
        $this->actingAs($this->user)->post(route('generations.store'), [...$this->form, 'client_request_id' => '0193a5c2-0000-7000-8000-00000000000b']);

        expect(Generation::query()->count())->toBe(2);
        Queue::assertPushed(SubmitGenerationJob::class, 2);
    });

    it('starts nothing when prices are unavailable, so nobody is charged', function () {
        $this->pricingResponse = Http::response('not json', 502);

        $this->actingAs($this->user)
            ->from(route('generations.create'))
            ->post(route('generations.store'), $this->form)
            ->assertRedirect(route('generations.create'))
            ->assertSessionHasErrors('form');

        expect(Generation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('requires a client request id', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), [...$this->form, 'client_request_id' => 'not-a-uuid'])
            ->assertSessionHasErrors('client_request_id');

        Queue::assertNothingPushed();
    });
});

describe('create', function () {
    it('shows the form with Pixelforge limits and the markup', function () {
        $this->actingAs($this->user)
            ->get(route('generations.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Create')
                ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('qualities', ['low', 'medium', 'high'])
                ->where('maxPromptCharacters', 4000)
                ->where('maxReferenceImages', 4)
                ->where('markupPercent', 25)
                ->where('initial', ['prompt' => '', 'size' => null, 'quality' => null]));
    });

    it('lists the user\'s latest generations under the form and what they have spent so far', function () {
        Generation::factory()->for($this->user)->succeeded()->count(2)->create();
        Generation::factory()->for($this->user)->failed()->create();
        Generation::factory()->for($this->user)->create();
        Generation::factory()->succeeded()->create();

        $this->actingAs($this->user)
            ->get(route('generations.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent', 4)
                ->has('serverNow')
                // Two succeeded generations at $0.3818875 each; failed and in-flight ones cost nothing.
                ->where('spent', '$0.763775'));
    });

    it('pre-fills the form to try a failed generation again, ignoring unknown values', function () {
        $this->actingAs($this->user)
            ->get(route('generations.create', ['prompt' => 'A lighthouse', 'size' => '1024x1536', 'quality' => 'ultra']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('initial', ['prompt' => 'A lighthouse', 'size' => '1024x1536', 'quality' => null]));
    });
});

describe('stored images', function () {
    beforeEach(function () {
        Storage::fake('local');
        config(['services.pixelforge.image_disk' => 'local']);
        $this->stored = Generation::factory()->for($this->user)->succeeded()->create([
            'prompt' => 'A lighthouse at dawn',
            'stored_image_path' => 'generations/1/stored.png',
            'image_url_expires_at' => now()->subDay(),
        ]);
        Storage::disk('local')->put('generations/1/stored.png', 'STORED');
    });

    it('shows our copy, which never expires, instead of Pixelforge\'s link', function () {
        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image.stored', true)
                ->where('generations.data.0.image.expired', false)
                ->where('generations.data.0.image.url', fn (string $url) => str_starts_with($url, '/images/'.$this->stored->id.'?') && str_contains($url, 'signature=')));
    });

    it('serves the image from a signed link, even signed out, and refuses an unsigned one', function () {
        $url = URL::signedRoute('generations.image', ['generation' => $this->stored->id], absolute: false);

        $this->get($url)->assertOk()->assertStreamedContent('STORED')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->get('/images/'.$this->stored->id)->assertForbidden();
    });

    it('falls back to Pixelforge\'s link while it works if our file went missing, then 404s', function () {
        Storage::disk('local')->delete('generations/1/stored.png');
        $url = URL::signedRoute('generations.image', ['generation' => $this->stored->id], absolute: false);

        $this->get($url)->assertNotFound();

        $this->stored->update(['image_url_expires_at' => now()->addHour()]);
        $this->get($url)->assertRedirect($this->stored->image_url);
    });

    it('downloads our copy without asking Pixelforge', function () {
        $response = $this->actingAs($this->user)->get(route('generations.download', $this->stored->id));

        $response->assertOk()->assertDownload('a-lighthouse-at-dawn-'.substr($this->stored->id, 0, 8).'.png');
        Http::assertNothingSent();
    });
});

describe('download', function () {
    it('sends the image as a file named after the prompt', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create(['prompt' => 'A lighthouse at dawn']);
        Http::fake([$generation->image_url => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $response = $this->actingAs($this->user)->get(route('generations.download', $generation->id));

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="a-lighthouse-at-dawn-'.substr($generation->id, 0, 8).'.png"')
            ->and($response->getContent())->toBe('PNGDATA');
    });

    it('explains instead of failing when the image has expired', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->from(route('generations.show', $generation->id))
            ->get(route('generations.download', $generation->id))
            ->assertRedirect(route('generations.show', $generation->id));
    });

    it('hides other users\' images', function () {
        $someoneElses = Generation::factory()->succeeded()->create();

        $this->actingAs($this->user)->get(route('generations.download', $someoneElses->id))->assertNotFound();
    });
});

describe('show', function () {
    it('shows one of the user\'s generations with its cost and the other attempts at the same prompt', function () {
        $generation = Generation::factory()->for($this->user)->succeeded()->create(['prompt' => 'A lighthouse', 'created_at' => now()]);
        $sibling = Generation::factory()->for($this->user)->failed()->create(['prompt' => 'A lighthouse', 'created_at' => now()->subMinute()]);
        Generation::factory()->for($this->user)->create(['prompt' => 'Something else', 'created_at' => now()->subMinutes(2)]);
        Generation::factory()->create(['prompt' => 'A lighthouse']);

        $this->actingAs($this->user)
            ->get(route('generations.show', $generation->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Show')
                ->where('generation.id', $generation->id)
                ->where('generation.charge.display', '$0.3818875')
                ->has('generation.reference_image_urls')
                ->has('variations', 1)
                ->where('variations.0.id', $sibling->id)
                ->has('more', 2)
                ->where('spent', '$0.3818875')
                ->has('serverNow'));
    });

    it('hides other users\' generations', function () {
        $someoneElses = Generation::factory()->create();

        $this->actingAs($this->user)
            ->get(route('generations.show', $someoneElses->id))
            ->assertNotFound();
    });

    it('is not found for an id that is not a uuid', function () {
        $this->actingAs($this->user)->get('/generations/not-a-uuid')->assertNotFound();
    });
});

describe('index', function () {
    it('shows only the user\'s own generations, newest first', function () {
        $older = Generation::factory()->for($this->user)->create(['created_at' => now()->subMinutes(5)]);
        $newer = Generation::factory()->for($this->user)->create(['created_at' => now()]);
        Generation::factory()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('serverNow')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.1.id', $older->id));
    });

    it('shows the estimate before completion and the exact charge after', function () {
        $pending = Generation::factory()->for($this->user)->create(['created_at' => now()->subMinute()]);
        $succeeded = Generation::factory()->for($this->user)->succeeded()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.id', $succeeded->id)
                ->where('generations.data.0.in_flight', false)
                ->where('generations.data.0.charge', ['nanos' => '381887500', 'display' => '$0.3818875'])
                ->where('generations.data.0.image.expired', false)
                ->where('generations.data.1.id', $pending->id)
                ->where('generations.data.1.in_flight', true)
                ->where('generations.data.1.charge', null)
                ->where('generations.data.1.estimate', [
                    'min_nanos' => '347750000',
                    'max_nanos' => '457150000',
                    'display' => '$0.34 – $0.46',
                ]));
    });

    it('shows failed generations as free with a plain-language reason', function () {
        Generation::factory()->for($this->user)->failed()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.in_flight', false)
                ->where('generations.data.0.charge.display', '$0.00')
                ->where('generations.data.0.failure.code', 'generation_failed')
                ->where('generations.data.0.failure.message', fn (string $message) => str_contains($message, 'You were not charged'))
                ->where('generations.data.0.finished_at', fn (?string $time) => $time !== null));
    });

    it('keeps watching an unconfirmed submission and does not call it free yet', function () {
        Generation::factory()->for($this->user)->failedLocally()->create([
            'failure_code' => 'submission_unconfirmed',
            'failure_message' => 'We could not confirm with Pixelforge whether this generation started.',
        ]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.in_flight', true)
                ->where('generations.data.0.charge', null)
                ->where('generations.data.0.finished_at', null)
                ->where('generations.data.0.failure.provisional', true));
    });

    it('flags images whose link has expired', function () {
        Generation::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('generations.data.0.image.expired', true));
    });

    it('pages with a cursor', function () {
        Generation::factory()->for($this->user)->count(25)->sequence(fn ($sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create();

        $firstPage = $this->actingAs($this->user)->get(route('generations.index'));
        $firstPage->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 20)
            ->where('generations.next_cursor', fn (?string $cursor) => $cursor !== null));

        $cursor = $firstPage->inertiaProps('generations.next_cursor');

        $this->actingAs($this->user)
            ->get(route('generations.index', ['cursor' => $cursor]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 5)
                ->where('generations.next_cursor', null));
    });

    it('sends a tampered cursor back to the first page instead of failing', function (string $cursor) {
        Generation::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->get(route('generations.index', ['cursor' => $cursor]))
            ->assertRedirect(route('generations.index'));
    })->with([
        'not base64 json' => ['garbage'],
        'id is not a uuid' => [rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-11-18 00:00:00', 'id' => 'abc', '_pointsToNextItems' => true])), '+/', '-_'), '=')],
        'missing created_at' => [rtrim(strtr(base64_encode(json_encode(['id' => '0193a5c2-0000-7000-8000-000000000001', '_pointsToNextItems' => true])), '+/', '-_'), '=')],
    ]);

    it('is only available to signed-in users', function () {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    });
});
