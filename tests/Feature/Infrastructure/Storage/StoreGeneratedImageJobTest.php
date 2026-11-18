<?php

use App\Application\Exceptions\ImageUnavailable;
use App\Application\UseCases\StoreGeneratedImage;
use App\Jobs\StoreGeneratedImageJob;
use App\Models\Generation as GenerationRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['services.pixelforge.image_disk' => 'local']);

    $this->record = GenerationRecord::factory()->succeeded()->create();
    $this->run = fn () => (new StoreGeneratedImageJob($this->record->id))->handle(app(StoreGeneratedImage::class));
});

it('copies the image to storage and records the path', function () {
    Http::fake([$this->record->image_url => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

    ($this->run)();

    $path = "generations/{$this->record->user_id}/{$this->record->id}.png";
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe('PNGDATA')
        ->and($this->record->fresh()->stored_image_path)->toBe($path);
});

it('does not fetch again once stored', function () {
    Http::fake([$this->record->image_url => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
    ($this->run)();
    ($this->run)();

    Http::assertSentCount(1);
});

it('gives up quietly on an expired link and stops retrying it', function () {
    Http::fake([$this->record->image_url => Http::response('gone', 410)]);

    ($this->run)();

    expect($this->record->fresh())
        ->stored_image_path->toBeNull()
        ->image_unstorable->toBeTrue();
});

it('throws on a temporary error so the queue retries', function () {
    Http::fake([$this->record->image_url => Http::response('busy', 503)]);

    expect(fn () => ($this->run)())->toThrow(ImageUnavailable::class);
});

it('refuses anything but a raster image', function (string $type) {
    Http::fake([$this->record->image_url => Http::response('<svg onload="alert(1)"/>', 200, ['Content-Type' => $type])]);

    ($this->run)();

    expect($this->record->fresh()->stored_image_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('generations');
})->with(['text/html', 'image/svg+xml', 'image/gif']);
