<?php

namespace App\Http\Controllers;

use App\Application\Exceptions\ClientRequestReused;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\UseCases\StartGenerationBatch;
use App\Domain\Billing\Markup;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Infrastructure\ReadModels\GenerationHistoryQuery;
use App\Infrastructure\Storage\FilesystemImageArchive;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationController extends Controller
{
    public function index(Request $request, GenerationHistoryQuery $history): Response|RedirectResponse
    {
        $userId = $request->user()->id;
        $cursor = $request->string('cursor')->toString();

        if ($cursor !== '' && ! $history->isValidCursor($cursor)) {
            return to_route('generations.index');
        }

        return Inertia::render('generations/Index', [
            'generations' => fn () => $history->forUser($userId),
            // Elapsed times are measured against the server's clock, not the browser's.
            'serverNow' => fn () => now()->toIso8601String(),
            'spent' => fn () => $history->totalChargedTo($userId)->toDisplayString(),
        ]);
    }

    public function show(Request $request, string $generation, GenerationHistoryQuery $history): Response
    {
        $userId = $request->user()->id;
        $item = $history->findForUser($userId, $generation);

        abort_if($item === null, 404);

        return Inertia::render('generations/Show', [
            // Already read for the 404 check; partial reloads run this action again, so it stays fresh.
            'generation' => $item,
            'variations' => fn () => $history->samePromptForUser($userId, (string) $item['prompt'], $generation),
            'more' => fn () => $history->recentForUser($userId, 6, $generation),
            'serverNow' => fn () => now()->toIso8601String(),
            'spent' => fn () => $history->totalChargedTo($userId)->toDisplayString(),
        ]);
    }

    /**
     * Download the image as a file. Pixelforge's link is on another origin, where the browser
     * ignores the download attribute, so the server fetches it and sends it as an attachment.
     */
    public function download(Request $request, string $generation, GenerationHistoryQuery $history): HttpResponse|StreamedResponse|RedirectResponse
    {
        $item = $history->findForUser($request->user()->id, $generation);

        abort_if($item === null, 404);

        $name = Str::slug(Str::limit((string) $item['prompt'], 40, '')) ?: 'image';
        $storedPath = $history->storedImagePath($generation);
        $disk = Storage::disk(FilesystemImageArchive::disk());

        // Our copy when we have it; otherwise (not copied yet, or the file went missing) Pixelforge's link while it lasts.
        if ($storedPath !== null && $disk->exists($storedPath)) {
            return $disk->download($storedPath, $name.'-'.substr($generation, 0, 8).'.'.pathinfo($storedPath, PATHINFO_EXTENSION));
        }

        $providerUrl = $history->liveProviderImageUrl($generation);

        if ($providerUrl === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This image is no longer available to download. Pixelforge keeps images for 24 hours.']);

            return back();
        }

        $response = Http::timeout(30)->get($providerUrl);

        if (! $response->successful()) {
            Log::warning('Could not fetch a generated image for download', ['generation_id' => $generation, 'status' => $response->status()]);
            Inertia::flash('toast', ['type' => 'error', 'message' => "Couldn't download the image right now. Try again in a moment."]);

            return back();
        }

        $type = $response->header('Content-Type') ?: 'image/png';
        $extension = match (true) {
            str_contains($type, 'jpeg') => 'jpg',
            str_contains($type, 'webp') => 'webp',
            default => 'png',
        };

        return response($response->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'-'.substr($generation, 0, 8).'.'.$extension.'"',
        ]);
    }

    public function create(Request $request, Markup $markup, GenerationHistoryQuery $history): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('generations/Create', [
            // The latest generations, shown live under the form.
            'recent' => fn () => $history->recentForUser($userId, 24),
            'serverNow' => fn () => now()->toIso8601String(),
            'spent' => fn () => $history->totalChargedTo($userId)->toDisplayString(),
            // "Try again" on a failed generation pre-fills the form; the values are validated on submit.
            'initial' => [
                'prompt' => mb_substr($request->string('prompt')->toString(), 0, GenerationRequest::MAX_PROMPT_CHARACTERS),
                'size' => ImageSize::tryFrom($request->string('size')->toString())?->value,
                'quality' => Quality::tryFrom($request->string('quality')->toString())?->value,
            ],
            'sizes' => array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases()),
            'qualities' => array_map(fn (Quality $quality): string => $quality->value, Quality::cases()),
            'maxPromptCharacters' => GenerationRequest::MAX_PROMPT_CHARACTERS,
            'maxReferenceImages' => GenerationRequest::MAX_REFERENCE_IMAGES,
            'markupPercent' => $markup->percent,
        ]);
    }

    public function store(StoreGenerationRequest $request, StartGenerationBatch $startGenerations): RedirectResponse
    {
        $userId = $request->user()->id;

        try {
            $started = $startGenerations->handle($userId, $request->clientRequestIds(), $request->toGenerationRequest());
        } catch (ProviderUnavailable|MalformedProviderPayload|ProviderRefused $unavailable) {
            // Without current prices there is no estimate to store, so nothing is started or charged.
            Log::warning('Pixelforge pricing unavailable when starting a generation', ['exception' => $unavailable->getMessage()]);

            return back()->withErrors(['form' => GenerationEstimateController::PRICING_UNAVAILABLE]);
        } catch (ClientRequestReused) {
            return back()->withErrors(['form' => 'This form was already submitted with different values. Reload the page to start a new generation.']);
        }

        // A repeated submission maps to the generations it already started, which are already queued.
        foreach ($started as $generation) {
            if ($generation->isNew) {
                SubmitGenerationJob::dispatch($generation->generationId)->afterCommit();
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => count($started) === 1
            ? 'Generation started. It usually takes about a minute.'
            : count($started).' images started. They usually take about a minute.']);

        // Back to the studio, which shows the new generation live under the form.
        return to_route('generations.create');
    }
}
