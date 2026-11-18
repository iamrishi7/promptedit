<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Ports\ProviderEventInbox;

/**
 * Record a verified webhook event. Pixelforge delivers at least once, so a repeated event id is
 * stored only once; processing happens separately so the webhook can answer quickly.
 */
final readonly class ReceiveProviderEvent
{
    public function __construct(private ProviderEventInbox $inbox) {}

    /**
     * @return bool whether the event still needs processing
     */
    public function handle(string $eventId, string $type, string $payloadJson): bool
    {
        return $this->inbox->record($eventId, $type, $payloadJson);
    }
}
