<?php

namespace App\Providers;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageArchive;
use App\Application\Ports\ImageGenerationProvider;
use App\Application\Ports\ProviderEventInbox;
use App\Application\Ports\TransactionRunner;
use App\Domain\Billing\Markup;
use App\Infrastructure\Persistence\EloquentGenerationRepository;
use App\Infrastructure\Persistence\EloquentProviderEventInbox;
use App\Infrastructure\Persistence\LaravelTransactionRunner;
use App\Infrastructure\Pixelforge\PixelforgeImageGenerationProvider;
use App\Infrastructure\Pixelforge\PixelforgePayloadMapper;
use App\Infrastructure\Pixelforge\PixelforgeWebhookSignature;
use App\Infrastructure\Storage\FilesystemImageArchive;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the application's ports to their infrastructure adapters.
 */
class HexagonServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(GenerationRepository::class, EloquentGenerationRepository::class);
        $this->app->bind(TransactionRunner::class, LaravelTransactionRunner::class);
        $this->app->bind(ImageArchive::class, FilesystemImageArchive::class);

        $this->app->bind(ImageGenerationProvider::class, fn (): ImageGenerationProvider => new PixelforgeImageGenerationProvider(
            baseUrl: config()->string('services.pixelforge.base_url'),
            apiKey: is_string(config('services.pixelforge.key')) ? config('services.pixelforge.key') : null,
            timeoutSeconds: config()->integer('services.pixelforge.timeout_seconds'),
            pricingCacheSeconds: config()->integer('services.pixelforge.pricing_cache_seconds'),
            mapper: new PixelforgePayloadMapper,
        ));

        $this->app->bind(ProviderEventInbox::class, EloquentProviderEventInbox::class);

        $this->app->bind(PixelforgeWebhookSignature::class, fn (): PixelforgeWebhookSignature => new PixelforgeWebhookSignature(
            is_string(config('services.pixelforge.webhook_secret')) ? config('services.pixelforge.webhook_secret') : null,
        ));

        $this->app->bind(Markup::class, fn (): Markup => new Markup(config()->integer('services.pixelforge.markup_percent')));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Pixelforge allows 60 requests/minute per account; leave headroom for polling and retries.
        RateLimiter::for(SubmitGenerationJob::RATE_LIMITER, fn (): Limit => Limit::perMinute(30));
    }
}
