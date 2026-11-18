<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix timestamp>,v1=<hex HMAC-SHA256 of
 * '<timestamp>.<raw body>' keyed with the webhook signing secret>".
 */
final readonly class PixelforgeWebhookSignature
{
    /** Reject signatures older (or further in the future) than this, to stop replays. */
    public const int TOLERANCE_SECONDS = 300;

    public function __construct(private ?string $secret) {}

    public function isConfigured(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * @param  string  $payload  the raw request body, exactly as received
     */
    public function isValid(string $payload, ?string $header, int $now): bool
    {
        return $this->rejectionReason($payload, $header, $now) === null;
    }

    /**
     * Why a request would be rejected, for logging (never includes the secret), or null if valid.
     *
     * @param  string  $payload  the raw request body, exactly as received
     */
    public function rejectionReason(string $payload, ?string $header, int $now): ?string
    {
        if (! $this->isConfigured()) {
            return 'signing secret not configured';
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return 'missing or malformed signature header';
        }

        $skew = $now - $timestamp;

        if (abs($skew) > self::TOLERANCE_SECONDS) {
            return "timestamp outside tolerance (skew {$skew}s)";
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return null;
            }
        }

        return 'signature mismatch (wrong secret or altered body)';
    }
}
