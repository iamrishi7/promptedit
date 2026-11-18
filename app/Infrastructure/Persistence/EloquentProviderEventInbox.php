<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Data\ProviderEvent;
use App\Application\Data\ProviderEventOutcome;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Ports\ProviderEventInbox;
use App\Infrastructure\Pixelforge\PixelforgePayloadMapper;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final readonly class EloquentProviderEventInbox implements ProviderEventInbox
{
    private const string TABLE = 'provider_events';

    public function __construct(private PixelforgePayloadMapper $mapper) {}

    public function record(string $eventId, string $type, string $payloadJson): bool
    {
        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'id' => $eventId,
            'type' => $type,
            'payload' => $payloadJson,
            'received_at' => now('UTC'),
        ]);

        if ($inserted === 1) {
            return true;
        }

        return DB::table(self::TABLE)->where('id', $eventId)->whereNull('processed_at')->exists();
    }

    public function findUnprocessedForUpdate(string $eventId): ?ProviderEvent
    {
        $row = DB::table(self::TABLE)
            ->where('id', $eventId)
            ->whereNull('processed_at')
            ->lockForUpdate()
            ->first(['id', 'type', 'payload']);

        if ($row === null) {
            return null;
        }

        $type = (string) $row->type;

        if (! str_starts_with($type, 'generation.')) {
            return new ProviderEvent((string) $row->id, $type, null);
        }

        try {
            $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
            $object = is_array($payload) && is_array($payload['data'] ?? null) ? ($payload['data']['object'] ?? null) : null;

            if (! is_array($object)) {
                throw MalformedProviderPayload::because('event has no data.object');
            }

            return new ProviderEvent((string) $row->id, $type, $this->mapper->toProviderUpdate($object));
        } catch (MalformedProviderPayload $malformed) {
            Log::error('Pixelforge webhook event is unreadable; marking it malformed', [
                'event_id' => $eventId,
                'type' => $type,
                'reason' => $malformed->getMessage(),
            ]);

            throw $malformed;
        }
    }

    public function markProcessed(string $eventId, ProviderEventOutcome $outcome): void
    {
        $updated = DB::table(self::TABLE)
            ->where('id', $eventId)
            ->whereNull('processed_at')
            ->update(['processed_at' => now('UTC'), 'outcome' => $outcome->value]);

        if ($updated !== 1) {
            throw new RuntimeException("Provider event {$eventId} was not pending processing.");
        }
    }

    public function unprocessedReceivedBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        $ids = DB::table(self::TABLE)
            ->whereNull('processed_at')
            ->where('received_at', '<', $cutoff->setTimezone(new DateTimeZone('UTC')))
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_values(array_map(fn (mixed $id): string => is_string($id) ? $id : throw new RuntimeException('Unexpected event id.'), $ids));
    }
}
