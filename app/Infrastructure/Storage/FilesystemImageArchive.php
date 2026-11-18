<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Exceptions\ImageUnavailable;
use App\Application\Ports\ImageArchive;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps generated images on a Laravel filesystem disk: `local` (private) in development, object
 * storage (S3-compatible) when deployed, since an app server's disk does not survive a redeploy.
 * Files are private; the app serves them through a signed route.
 */
final readonly class FilesystemImageArchive implements ImageArchive
{
    private const int MAX_BYTES = 25 * 1024 * 1024;

    private const int TIMEOUT_SECONDS = 30;

    /** Raster formats only: anything else (SVG especially) could carry script on our origin. */
    private const array EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function store(string $generationId, int $userId, string $sourceUrl): string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                ->get($sourceUrl);
        } catch (ConnectionException $exception) {
            throw new ImageUnavailable("Could not reach the image for generation {$generationId}.", previous: $exception);
        }

        if ($response->clientError()) {
            // Expired or refused: asking again will not help.
            throw new ImageUnavailable("The image for generation {$generationId} answered {$response->status()}.", permanent: true);
        }

        if (! $response->successful()) {
            throw new ImageUnavailable("The image for generation {$generationId} answered {$response->status()}.");
        }

        $body = $response->body();
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $extension = self::EXTENSIONS[$type] ?? null;

        if ($extension === null || $body === '' || strlen($body) > self::MAX_BYTES) {
            throw new ImageUnavailable("The image for generation {$generationId} is not a usable image ({$type}, ".strlen($body).' bytes).', permanent: true);
        }

        $path = "generations/{$userId}/{$generationId}.{$extension}";

        Storage::disk(self::disk())->put($path, $body);

        return $path;
    }

    public static function disk(): string
    {
        return config()->string('services.pixelforge.image_disk');
    }
}
