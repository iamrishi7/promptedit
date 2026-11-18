<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Provider webhooks. Registered outside the "web" middleware group: no session, cookies or CSRF
| (Pixelforge cannot send a CSRF token). Requests are authenticated by their HMAC signature instead.
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
