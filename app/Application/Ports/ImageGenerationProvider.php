<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Data\ProviderDailyUsage;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Domain\Billing\PricingSchedule;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ProviderUpdate;
use DateTimeImmutable;

/**
 * The image generation service (Pixelforge).
 */
interface ImageGenerationProvider
{
    /**
     * Submit a generation. Retrying with the same generation id never creates a second one.
     *
     * @throws ProviderUnavailable when nothing was decided yet and the call may be retried later
     * @throws ProviderRefused when the provider definitively refused; nothing was created or billed
     * @throws MalformedProviderPayload when the provider answered with data we cannot read
     */
    public function submit(string $generationId, GenerationRequest $request): ProviderUpdate;

    /**
     * Current state of a generation the provider accepted.
     *
     * @throws ProviderUnavailable
     * @throws ProviderRefused
     * @throws MalformedProviderPayload
     */
    public function fetch(string $providerId): ProviderUpdate;

    /**
     * Look up a submission by our generation id (sent as metadata), without creating anything.
     * Returns null when Pixelforge's records reach back past $submittedAfter without a match, i.e. it
     * never received that submission.
     *
     * @throws ProviderUnavailable also when the lookup could not reach back far enough this time
     * @throws ProviderRefused
     * @throws MalformedProviderPayload
     */
    public function findSubmitted(string $generationId, DateTimeImmutable $submittedAfter): ?ProviderUpdate;

    /**
     * Current published rates and token rules, used for estimates.
     *
     * @throws ProviderUnavailable
     * @throws MalformedProviderPayload
     */
    public function pricing(): PricingSchedule;

    /**
     * What Pixelforge billed this API key per UTC day, both dates inclusive (at most 92 days).
     * Days without usage are included with zero.
     *
     * @return list<ProviderDailyUsage>
     *
     * @throws ProviderUnavailable
     * @throws ProviderRefused
     * @throws MalformedProviderPayload
     */
    public function dailyUsage(string $fromDate, string $toDate): array;
}
