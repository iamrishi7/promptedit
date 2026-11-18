<?php

namespace App\Http\Controllers;

use App\Infrastructure\ReadModels\GenerationHistoryQuery;
use App\Infrastructure\Storage\FilesystemImageArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves our stored copy of a generated image. The link is signed (see GenerationHistoryQuery), so
 * it works without signing in: the owner's pages use it, and it is the link they share. Without a
 * valid signature the route answers 403, so ids alone reveal nothing.
 *
 * If the file has gone missing (e.g. a non-durable disk after a redeploy), Pixelforge's own link is
 * used while it still works; after that the image is gone (404).
 */
class GenerationImageController extends Controller
{
    public function __invoke(string $generation, GenerationHistoryQuery $history): StreamedResponse|RedirectResponse
    {
        $path = $history->storedImagePath($generation);
        $disk = Storage::disk(FilesystemImageArchive::disk());

        if ($path !== null && $disk->exists($path)) {
            return $disk->response($path, headers: [
                // The file at this path never changes, so browsers may keep it.
                'Cache-Control' => 'private, max-age=31536000, immutable',
                // Served from the app's own origin: never let a browser treat it as anything but an image.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }

        $providerUrl = $history->liveProviderImageUrl($generation);

        abort_if($providerUrl === null, 404);

        return redirect()->away($providerUrl);
    }
}
