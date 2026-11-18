<?php

use App\Application\Ports\GenerationRepository;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\ProviderUpdate;
use App\Domain\Generation\Quality;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->repository = app(GenerationRepository::class);
    $this->user = User::factory()->create();
    $this->request = new GenerationRequest('A lighthouse on a cliff at dawn', ImageSize::Landscape, Quality::High, ['https://example.com/style.png']);
    $this->estimate = CostEstimate::for($this->request, pixelforgeDocsPricing(), new Markup(25));

    $this->id = $this->repository->nextIdentity();
    $this->repository->add(new Generation($this->id, $this->user->id, $this->request, new Markup(25)), $this->estimate, '0193a5c2-0000-7000-8000-000000000001');

    $this->succeeded = fn (string $completedAt = '2026-11-04T14:03:29Z') => new ProviderUpdate(
        providerId: 'gen_abc',
        status: GenerationStatus::Succeeded,
        usage: new TokenUsage(12, 765, 6450, 3184),
        rates: pixelforgeDocsRates(),
        imageUrl: 'https://pixelforge.promptedit.dev/images/gen_abc.png',
        imageUrlExpiresAt: new DateTimeImmutable('2026-11-05T14:03:29Z'),
        completedAt: new DateTimeImmutable($completedAt),
    );

    $this->lockApplyAndSave = function (Closure $change): void {
        DB::transaction(function () use ($change) {
            $generation = $this->repository->findForUpdate($this->id);
            $change($generation);
            $this->repository->save($generation);
        });
    };
});

it('stores a new pending generation with its request and estimate in nanodollars', function () {
    $record = GenerationRecord::query()->findOrFail($this->id);

    expect($record->status)->toBe('pending')
        ->and($record->size)->toBe('1536x1024')
        ->and($record->reference_image_urls)->toBe(['https://example.com/style.png'])
        ->and($record->estimated_min_charge_nanos)->toBe($this->estimate->minimumCharge->nanos)
        ->and($record->estimated_max_charge_nanos)->toBe($this->estimate->maximumCharge->nanos)
        ->and($record->charge_nanos)->toBeNull();
});

it('round-trips a succeeded generation without losing a nanodollar', function () {
    ($this->lockApplyAndSave)(fn (Generation $generation) => $generation->apply(($this->succeeded)()));

    $reloaded = $this->repository->find($this->id);

    expect($reloaded?->status())->toBe(GenerationStatus::Succeeded)
        ->and($reloaded?->providerId())->toBe('gen_abc')
        ->and($reloaded?->usage())->toEqual(new TokenUsage(12, 765, 6450, 3184))
        ->and($reloaded?->cost()?->providerCost->nanos)->toBe(305_510_000)
        ->and($reloaded?->cost()?->charge->nanos)->toBe(381_887_500)
        ->and($reloaded?->completedAt()?->format(DATE_ATOM))->toBe('2026-11-04T14:03:29+00:00')
        ->and($reloaded?->request)->toEqual($this->request);
});

it('stores completion time in UTC so the generation is billed on the right UTC day', function () {
    ($this->lockApplyAndSave)(fn (Generation $generation) => $generation->apply(($this->succeeded)('2026-11-17T23:30:00-05:00')));

    expect(GenerationRecord::query()->findOrFail($this->id)->completed_at?->format('Y-m-d H:i'))->toBe('2026-11-18 04:30');
});

it('round-trips a free failure, keeping long provider messages', function () {
    $message = str_repeat('The model could not produce an image. ', 20);

    ($this->lockApplyAndSave)(fn (Generation $generation) => $generation->failBeforeAcceptance(new GenerationFailure('generation_failed', $message)));

    $reloaded = $this->repository->find($this->id);
    expect($reloaded?->status())->toBe(GenerationStatus::Failed)
        ->and($reloaded?->failure()?->message)->toBe($message)
        ->and($reloaded?->cost()?->charge->nanos)->toBe(0);
});

it('refuses to save a generation that was not locked in the current transaction', function () {
    $generation = $this->repository->find($this->id);
    $generation?->failBeforeAcceptance(GenerationFailure::submissionAbandoned());

    DB::transaction(fn () => $this->repository->save($generation));
})->throws(LogicException::class);

it('finds a generation by its Pixelforge id', function () {
    $record = GenerationRecord::factory()->queued()->create();

    $generation = DB::transaction(fn () => $this->repository->findByProviderIdForUpdate((string) $record->provider_id));

    expect($generation?->id)->toBe($record->id);
});

it('never links two generations to the same Pixelforge generation', function () {
    GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_same']);

    GenerationRecord::factory()->queued()->create(['provider_id' => 'gen_same']);
})->throws(UniqueConstraintViolationException::class);

it('accepts every valid generation state', function (string $state) {
    expect(GenerationRecord::factory()->{$state}()->create()->exists)->toBeTrue();
})->with(['queued', 'processing', 'succeeded', 'failed', 'failedLocally']);

it('rejects rows that break billing invariants at the database level', function (array $attributes) {
    GenerationRecord::factory()->create($attributes);
})->with([
    'negative charge' => [['status' => 'succeeded', 'provider_id' => 'gen_x', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0, 'provider_cost_nanos' => 5, 'charge_nanos' => -1, 'completed_at' => now()]],
    'charge below provider cost' => [['status' => 'succeeded', 'provider_id' => 'gen_x', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0, 'provider_cost_nanos' => 5, 'charge_nanos' => 4, 'completed_at' => now()]],
    'succeeded without cost' => [['status' => 'succeeded', 'provider_id' => 'gen_x', 'completed_at' => now()]],
    'failed but charged' => [['status' => 'failed', 'provider_cost_nanos' => 5, 'charge_nanos' => 7]],
    'pending with a cost' => [['provider_cost_nanos' => 0, 'charge_nanos' => 0]],
    'queued without Pixelforge id' => [['status' => 'queued']],
    'partial token usage' => [['input_text_tokens' => 12]],
    'unknown status' => [['status' => 'done']],
])->throws(QueryException::class);

it('returns the existing generation instead of inserting when a client request is repeated', function () {
    $repeat = new Generation($this->repository->nextIdentity(), $this->user->id, $this->request, new Markup(25));

    $returnedId = DB::transaction(fn () => $this->repository->add($repeat, $this->estimate, '0193a5c2-0000-7000-8000-000000000001'));

    expect($returnedId)->toBe($this->id)
        ->and(GenerationRecord::query()->count())->toBe(1);
});

it('scopes client request ids to the user', function () {
    $otherUser = User::factory()->create();
    $other = new Generation($this->repository->nextIdentity(), $otherUser->id, $this->request, new Markup(25));

    expect($this->repository->add($other, $this->estimate, '0193a5c2-0000-7000-8000-000000000001'))->toBe($other->id)
        ->and(GenerationRecord::query()->count())->toBe(2);
});

it('stores the request and estimate exactly as the model reads them back', function () {
    $record = GenerationRecord::query()->findOrFail($this->id);

    expect($record->reference_image_urls)->toBe(['https://example.com/style.png'])
        ->and($record->estimated_max_charge_nanos)->toBe($this->estimate->maximumCharge->nanos)
        ->and($record->client_request_id)->toBe('0193a5c2-0000-7000-8000-000000000001')
        ->and($record->created_at)->not->toBeNull()
        ->and($this->repository->find($this->id)?->request->referenceImageUrls)->toBe(['https://example.com/style.png']);
});
