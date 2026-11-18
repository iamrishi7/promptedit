<?php

use App\Application\Exceptions\ImageUnavailable;
use App\Application\Ports\ImageArchive;
use App\Application\UseCases\StoreGeneratedImage;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\Markup;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\ProviderUpdate;
use App\Domain\Generation\Quality;
use Tests\Fakes\ImmediateTransactionRunner;
use Tests\Fakes\InMemoryGenerationRepository;

beforeEach(function () {
    $this->generations = new InMemoryGenerationRepository;
    $this->archive = new class implements ImageArchive
    {
        /** @var list<string> */
        public array $stored = [];

        public ?ImageUnavailable $failWith = null;

        public function store(string $generationId, int $userId, string $sourceUrl): string
        {
            if ($this->failWith !== null) {
                throw $this->failWith;
            }

            $this->stored[] = $sourceUrl;

            return "generations/{$userId}/{$generationId}.png";
        }
    };
    $this->store = new StoreGeneratedImage($this->generations, $this->archive, new ImmediateTransactionRunner);

    $this->generation = new Generation('0193a7c0-0000-7000-8000-000000000009', 7, new GenerationRequest('A lighthouse', ImageSize::Square, Quality::Low), new Markup(25));
});

function addToRepository(InMemoryGenerationRepository $generations, Generation $generation, bool $succeeded): void
{
    if ($succeeded) {
        succeedForStorage($generation);
    }

    $generations->add($generation, CostEstimate::for($generation->request, pixelforgeDocsPricing(), new Markup(25)));
}

function succeedForStorage(Generation $generation): void
{
    $generation->apply(new ProviderUpdate(
        providerId: 'gen_1',
        status: GenerationStatus::Succeeded,
        usage: new TokenUsage(12, 0, 272, 100),
        rates: pixelforgeDocsRates(),
        imageUrl: 'https://pixelforge.promptedit.dev/images/gen_1.png',
        imageUrlExpiresAt: new DateTimeImmutable('+1 day'),
        completedAt: new DateTimeImmutable,
    ));
}

it('copies a succeeded image once and remembers where', function () {
    addToRepository($this->generations, $this->generation, succeeded: true);

    $this->store->handle($this->generation->id);
    $this->store->handle($this->generation->id);

    expect($this->archive->stored)->toBe(['https://pixelforge.promptedit.dev/images/gen_1.png'])
        ->and($this->generations->find($this->generation->id)?->storedImagePath())->toBe('generations/7/0193a7c0-0000-7000-8000-000000000009.png');
});

it('does nothing for a generation without an image yet', function () {
    addToRepository($this->generations, $this->generation, succeeded: false);

    $this->store->handle($this->generation->id);

    expect($this->archive->stored)->toBe([]);
});

it('leaves the generation unchanged when the image cannot be fetched', function () {
    addToRepository($this->generations, $this->generation, succeeded: true);
    $this->archive->failWith = new ImageUnavailable('expired', permanent: true);

    expect(fn () => $this->store->handle($this->generation->id))->toThrow(ImageUnavailable::class)
        ->and($this->generations->find($this->generation->id)?->storedImagePath())->toBeNull();
});
