<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Data\ProviderDailyUsage;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\PricingSchedule;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ProviderUpdate;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class FakeImageGenerationProvider implements ImageGenerationProvider
{
    /** @var list<string> generation ids submitted, in order */
    public array $submitted = [];

    /** @var list<ProviderUpdate|Throwable> */
    private array $submitOutcomes = [];

    private ?Throwable $pricingFailure = null;

    public function __construct(private PricingSchedule $pricing) {}

    public function pricingFailsWith(Throwable $failure): void
    {
        $this->pricingFailure = $failure;
    }

    /**
     * Queue what the next submit() returns or throws.
     */
    public function willRespondTo(ProviderUpdate|Throwable $outcome): self
    {
        $this->submitOutcomes[] = $outcome;

        return $this;
    }

    public function submit(string $generationId, GenerationRequest $request): ProviderUpdate
    {
        $this->submitted[] = $generationId;
        $outcome = array_shift($this->submitOutcomes)
            ?? new ProviderUpdate('gen_'.$generationId, GenerationStatus::Queued, $generationId);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function fetch(string $providerId): ProviderUpdate
    {
        throw new RuntimeException('fetch() is not faked yet.');
    }

    public function findSubmitted(string $generationId, DateTimeImmutable $submittedAfter): ?ProviderUpdate
    {
        throw new RuntimeException('findSubmitted() is not faked yet.');
    }

    /** @var list<ProviderDailyUsage> */
    public array $usage = [];

    public function dailyUsage(string $fromDate, string $toDate): array
    {
        return array_values(array_filter($this->usage, fn (ProviderDailyUsage $day): bool => $day->date >= $fromDate && $day->date <= $toDate));
    }

    public function pricing(): PricingSchedule
    {
        if ($this->pricingFailure !== null) {
            throw $this->pricingFailure;
        }

        return $this->pricing;
    }
}
