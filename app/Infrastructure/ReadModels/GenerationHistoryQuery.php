<?php

declare(strict_types=1);

namespace App\Infrastructure\ReadModels;

use App\Domain\Billing\Money;
use App\Domain\Generation\GenerationFailure;
use App\Models\Generation as GenerationRecord;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * A user's generations for the history page, newest first, shaped for display. Money goes out as
 * nanodollar strings plus server-formatted text, so the browser never does money arithmetic.
 */
final class GenerationHistoryQuery
{
    private const array COLUMNS = [
        'id', 'prompt', 'size', 'quality', 'reference_image_urls', 'status',
        'estimated_min_charge_nanos', 'estimated_max_charge_nanos', 'charge_nanos',
        'image_url', 'image_url_expires_at', 'stored_image_path', 'failure_code', 'failure_message',
        'completed_at', 'created_at', 'updated_at',
    ];

    /**
     * @return CursorPaginator<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $perPage = 20): CursorPaginator
    {
        // Walks the (user_id, created_at, id) index backwards; id breaks ties within one timestamp.
        return GenerationRecord::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, self::COLUMNS)
            ->withQueryString()
            ->through(fn (GenerationRecord $record): array => $this->present($record));
    }

    /**
     * The user's latest generations, for the live list under the generate form.
     *
     * @return list<array<string, mixed>>
     */
    public function recentForUser(int $userId, int $limit = 12, ?string $exceptId = null): array
    {
        return array_values(GenerationRecord::query()
            ->where('user_id', $userId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(self::COLUMNS)
            ->map(fn (GenerationRecord $record): array => $this->present($record))
            ->all());
    }

    /**
     * One generation, only if it belongs to the user (so another user's id is simply "not found").
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, string $generationId): ?array
    {
        $record = GenerationRecord::query()
            ->where('user_id', $userId)
            ->whereKey($generationId)
            ->first(self::COLUMNS);

        return $record === null ? null : [
            ...$this->present($record),
            'reference_image_urls' => $record->reference_image_urls,
        ];
    }

    /**
     * Other generations of the user made from exactly the same prompt, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function samePromptForUser(int $userId, string $prompt, string $exceptId, int $limit = 6): array
    {
        return array_values(GenerationRecord::query()
            ->where('user_id', $userId)
            ->where('prompt', $prompt)
            ->whereKeyNot($exceptId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(self::COLUMNS)
            ->map(fn (GenerationRecord $record): array => $this->present($record))
            ->all());
    }

    /**
     * Where our copy of a generation's image is, if it has been stored.
     */
    public function storedImagePath(string $generationId): ?string
    {
        $path = GenerationRecord::query()->whereKey($generationId)->value('stored_image_path');

        return is_string($path) ? $path : null;
    }

    /**
     * Pixelforge's own link to the image while it still works (24 hours after completion).
     */
    public function liveProviderImageUrl(string $generationId): ?string
    {
        $record = GenerationRecord::query()->whereKey($generationId)->first(['id', 'image_url', 'image_url_expires_at']);

        if ($record === null || $record->image_url === null || ($record->image_url_expires_at?->isPast() ?? false)) {
            return null;
        }

        return $record->image_url;
    }

    /**
     * Everything the user has been charged: the sum of exact charges on succeeded generations.
     */
    public function totalChargedTo(int $userId): Money
    {
        $sum = GenerationRecord::query()
            ->where('user_id', $userId)
            ->where('status', 'succeeded')
            ->sum('charge_nanos');

        return Money::fromNanos((int) $sum);
    }

    /**
     * The cursor comes from the URL, so a tampered one would reach Postgres as an invalid uuid or
     * timestamp (a 500). Only our own (created_at, id) shape is accepted.
     */
    public function isValidCursor(string $encoded): bool
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return false;
        }

        try {
            $id = $cursor->parameter('id');
            $createdAt = $cursor->parameter('created_at');
        } catch (Throwable) {
            return false;
        }

        return is_string($id) && Str::isUuid($id) && is_string($createdAt) && strtotime($createdAt) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GenerationRecord $record): array
    {
        $failure = $record->failure_code === null ? null : new GenerationFailure($record->failure_code, (string) $record->failure_message);
        $minimum = Money::fromNanos($record->estimated_min_charge_nanos);
        $maximum = Money::fromNanos($record->estimated_max_charge_nanos);
        $isFinal = in_array($record->status, ['succeeded', 'failed'], true);
        // An unconfirmed submission may still turn out to have succeeded, so keep watching it.
        $inFlight = ! $isFinal || ($failure?->isProvisional() ?? false);
        $finished = ! $inFlight;

        return [
            'id' => $record->id,
            'prompt' => $record->prompt,
            'size' => $record->size,
            'quality' => $record->quality,
            'reference_image_count' => count($record->reference_image_urls),
            'status' => $record->status,
            'in_flight' => $inFlight,
            'estimate' => [
                'min_nanos' => (string) $minimum->nanos,
                'max_nanos' => (string) $maximum->nanos,
                // Rounded outward to cents so the range shown always contains the real charge.
                'display' => $minimum->floorToCents()->toDisplayString().' – '.$maximum->ceilToCents()->toDisplayString(),
            ],
            'charge' => $record->charge_nanos === null || $inFlight ? null : [
                'nanos' => (string) $record->charge_nanos,
                'display' => Money::fromNanos($record->charge_nanos)->toDisplayString(),
            ],
            'failure' => $failure === null ? null : [
                'code' => $failure->code,
                'message' => $failure->userMessage(),
                'provisional' => $failure->isProvisional(),
            ],
            'image' => $this->image($record),
            'created_at' => $this->iso($record->created_at),
            // Failures before Pixelforge accepted the request have no completed_at.
            'finished_at' => $finished ? $this->iso($record->completed_at ?? $record->updated_at) : null,
        ];
    }

    /**
     * Our stored copy once there is one: a permanent, signed, relative link that also works when
     * shared. Until then, Pixelforge's link, which expires 24 hours after completion.
     *
     * @return array{url: string, expires_at: string|null, expired: bool, stored: bool}|null
     */
    private function image(GenerationRecord $record): ?array
    {
        if ($record->stored_image_path !== null) {
            return [
                'url' => URL::signedRoute('generations.image', ['generation' => $record->id], absolute: false),
                'expires_at' => null,
                'expired' => false,
                'stored' => true,
            ];
        }

        return $record->image_url === null ? null : [
            'url' => $record->image_url,
            'expires_at' => $record->image_url_expires_at?->toIso8601String(),
            'expired' => $record->image_url_expires_at?->isPast() ?? false,
            'stored' => false,
        ];
    }

    private function iso(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}
