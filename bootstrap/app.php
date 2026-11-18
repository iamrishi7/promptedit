<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Deployed behind a TLS-terminating reverse proxy (Traefik in Dokploy): trust its X-Forwarded-*
        // headers so URLs, redirects and secure cookies use https. The app container is only reachable
        // through that proxy on the internal network.
        $middleware->trustProxies(at: '*');

        // Keep accepting provider webhooks during maintenance: recording them is safe, and Pixelforge
        // stops retrying after about 28 minutes.
        $middleware->preventRequestsDuringMaintenance(except: ['webhooks/*']);

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
