<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Data\UnconfirmedSubmission;
use App\Application\Ports\GenerationRepository;
use App\Domain\Billing\CostEstimate;
use App\Domain\Billing\GenerationCost;
use App\Domain\Billing\Markup;
use App\Domain\Billing\Money;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Models\Generation as GenerationRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class EloquentGenerationRepository implements GenerationRepository
{
    /**
     * Ids locked with FOR UPDATE in the current transaction. save() only accepts these, so a stale
     * unlocked read can never overwrite a newer state (e.g. wipe a recorded charge).
     *
     * @var array<string, true>
     */
    private array $lockedIds = [];

    public function nextIdentity(): string
    {
        return (string) Str::uuid7();
    }

    public function add(Generation $generation, CostEstimate $estimate, string $clientRequestId): string
    {
        $now = now('UTC');

        // ON CONFLICT DO NOTHING rather than catching the unique violation: in Postgres a failed
        // statement aborts the surrounding transaction. A concurrent duplicate waits for the first
        // insert to commit, then finds its row below.
        $inserted = GenerationRecord::query()->toBase()->insertOrIgnoreReturning([
            'id' => $generation->id,
            'user_id' => $generation->userId,
            'client_request_id' => $clientRequestId,
            'prompt' => $generation->request->prompt,
            'size' => $generation->request->size->value,
            'quality' => $generation->request->quality->value,
            'reference_image_urls' => json_encode($generation->request->referenceImageUrls, JSON_THROW_ON_ERROR),
            'markup_percent' => $generation->markup->percent,
            'estimated_min_provider_cost_nanos' => $estimate->minimumProviderCost->nanos,
            'estimated_max_provider_cost_nanos' => $estimate->maximumProviderCost->nanos,
            'estimated_min_charge_nanos' => $estimate->minimumCharge->nanos,
            'estimated_max_charge_nanos' => $estimate->maximumCharge->nanos,
            ...$this->stateColumns($generation),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['id'], ['user_id', 'client_request_id']);

        if ($inserted->isNotEmpty()) {
            return $generation->id;
        }

        $existingId = GenerationRecord::query()
            ->where('user_id', $generation->userId)
            ->where('client_request_id', $clientRequestId)
            ->value('id');

        return is_string($existingId) ? $existingId : throw new RuntimeException("Generation for client request {$clientRequestId} vanished.");
    }

    public function find(string $id): ?Generation
    {
        $record = GenerationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByClientRequest(int $userId, string $clientRequestId): ?Generation
    {
        $record = GenerationRecord::query()
            ->where('user_id', $userId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findForUpdate(string $id): ?Generation
    {
        return $this->locked(GenerationRecord::query()->lockForUpdate()->find($id));
    }

    public function findByProviderIdForUpdate(string $providerId): ?Generation
    {
        return $this->locked(GenerationRecord::query()->where('provider_id', $providerId)->lockForUpdate()->first());
    }

    public function save(Generation $generation): void
    {
        if (DB::transactionLevel() === 0 || ! isset($this->lockedIds[$generation->id])) {
            throw new LogicException("Generation {$generation->id} must be loaded with a ...ForUpdate method in the current transaction before saving.");
        }

        $updated = GenerationRecord::query()->whereKey($generation->id)->update($this->stateColumns($generation));

        if ($updated !== 1) {
            throw new RuntimeException("Generation {$generation->id} does not exist.");
        }
    }

    public function inFlightNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array
    {
        $cutoff = $this->utc($cutoff);

        return $this->ids(GenerationRecord::query()
            ->whereRaw("status in ('queued', 'processing')") // literal list so Postgres can use the partial index
            ->where('updated_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('last_polled_at')->orWhere('last_polled_at', '<', $cutoff))
            ->orderByRaw('last_polled_at asc nulls first')
            ->limit($limit)
            ->get(['id']));
    }

    public function unconfirmedNotCheckedSince(DateTimeImmutable $cutoff, int $limit): array
    {
        $cutoff = $this->utc($cutoff);

        return array_values(GenerationRecord::query()
            ->where('status', 'failed')
            ->where('failure_code', GenerationFailure::SUBMISSION_UNCONFIRMED)
            ->whereNull('provider_id')
            ->where(fn ($query) => $query->whereNull('last_polled_at')->orWhere('last_polled_at', '<', $cutoff))
            ->orderByRaw('last_polled_at asc nulls first')
            ->limit($limit)
            ->get(['id', 'created_at'])
            ->map(fn (GenerationRecord $record): UnconfirmedSubmission => new UnconfirmedSubmission(
                $record->id,
                ($record->created_at ?? throw new RuntimeException("Generation {$record->id} has no created_at."))->toDateTimeImmutable(),
            ))
            ->all());
    }

    public function pendingCreatedBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->ids(GenerationRecord::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $this->utc($cutoff))
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id']));
    }

    public function succeededWithoutStoredImage(DateTimeImmutable $now, int $limit): array
    {
        return $this->ids(GenerationRecord::query()
            ->whereRaw("status = 'succeeded'") // literal so Postgres can use the partial index
            ->whereNull('stored_image_path')
            ->where('image_unstorable', false)
            ->whereNotNull('image_url')
            // An expired link can't be copied any more; the soonest to expire go first.
            ->where('image_url_expires_at', '>', $this->utc($now))
            ->orderBy('image_url_expires_at')
            ->limit($limit)
            ->get(['id']));
    }

    public function markImageUnstorable(string $id): void
    {
        // Base query: bookkeeping must not bump updated_at, which tracks real state changes.
        GenerationRecord::query()->whereKey($id)->toBase()->update(['image_unstorable' => true]);
    }

    public function markChecked(string $id, DateTimeImmutable $at): void
    {
        // Base query: bookkeeping must not bump updated_at, which tracks real state changes.
        GenerationRecord::query()->whereKey($id)->toBase()->update(['last_polled_at' => $this->utc($at)]);
    }

    /**
     * @param  Collection<int, GenerationRecord>  $records
     * @return list<string>
     */
    private function ids(Collection $records): array
    {
        return array_values($records->map(fn (GenerationRecord $record): string => $record->id)->all());
    }

    private function locked(?GenerationRecord $record): ?Generation
    {
        if ($record === null) {
            return null;
        }

        $this->lockedIds[$record->id] = true;

        return $this->toDomain($record);
    }

    /**
     * Columns that change over a generation's life. The request and estimate never change.
     *
     * @return array<string, mixed>
     */
    private function stateColumns(Generation $generation): array
    {
        $usage = $generation->usage();
        $cost = $generation->cost();
        $failure = $generation->failure();

        return [
            'status' => $generation->status()->value,
            'provider_id' => $generation->providerId(),
            'submission_outcome_unknown' => $generation->isSubmissionOutcomeUnknown(),
            'submission_attempts' => $generation->submissionAttempts(),
            'busy_refusals' => $generation->busyRefusals(),
            'input_text_tokens' => $usage?->inputTextTokens,
            'input_image_tokens' => $usage?->inputImageTokens,
            'output_tokens' => $usage?->outputTokens,
            'reasoning_tokens' => $usage?->reasoningTokens,
            'provider_cost_nanos' => $cost?->providerCost->nanos,
            'charge_nanos' => $cost?->charge->nanos,
            'image_url' => $generation->imageUrl(),
            'image_url_expires_at' => $this->utc($generation->imageUrlExpiresAt()),
            'stored_image_path' => $generation->storedImagePath(),
            'failure_code' => $failure === null ? null : mb_substr($failure->code, 0, 64),
            'failure_message' => $failure?->message,
            'completed_at' => $this->utc($generation->completedAt()),
        ];
    }

    /**
     * Timestamp columns hold UTC wall-clock time, so a completion is billed on the right UTC day
     * whatever offset Pixelforge sent.
     */
    private function utc(?DateTimeImmutable $time): ?DateTimeImmutable
    {
        return $time?->setTimezone(new DateTimeZone('UTC'));
    }

    private function toDomain(GenerationRecord $record): Generation
    {
        return new Generation(
            id: $record->id,
            userId: $record->user_id,
            request: new GenerationRequest(
                $record->prompt,
                ImageSize::from($record->size),
                Quality::from($record->quality),
                $record->reference_image_urls,
            ),
            markup: new Markup($record->markup_percent),
            status: GenerationStatus::from($record->status),
            providerId: $record->provider_id,
            submissionOutcomeUnknown: $record->submission_outcome_unknown,
            usage: $record->input_text_tokens === null ? null : new TokenUsage(
                (int) $record->input_text_tokens,
                (int) $record->input_image_tokens,
                (int) $record->output_tokens,
                (int) $record->reasoning_tokens,
            ),
            cost: $record->provider_cost_nanos === null || $record->charge_nanos === null ? null : new GenerationCost(
                Money::fromNanos($record->provider_cost_nanos),
                Money::fromNanos($record->charge_nanos),
            ),
            imageUrl: $record->image_url,
            imageUrlExpiresAt: $record->image_url_expires_at?->toDateTimeImmutable(),
            failure: $record->failure_code === null ? null : new GenerationFailure($record->failure_code, (string) $record->failure_message),
            completedAt: $record->completed_at?->toDateTimeImmutable(),
            submissionAttempts: $record->submission_attempts,
            busyRefusals: $record->busy_refusals,
            storedImagePath: $record->stored_image_path,
        );
    }
}
