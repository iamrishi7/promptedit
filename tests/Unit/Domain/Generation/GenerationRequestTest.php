<?php

use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\InvalidGenerationRequest;
use App\Domain\Generation\Quality;

it('accepts a prompt of exactly 4000 multibyte characters', function () {
    $request = new GenerationRequest(str_repeat('é', 4000), ImageSize::Square, Quality::Medium);

    expect($request->promptCharacterCount())->toBe(4000);
});

it('accepts up to 4 http(s) reference images', function () {
    $request = new GenerationRequest('a lighthouse', ImageSize::Square, Quality::Medium, [
        'https://example.com/1.png',
        'http://example.com/2.png',
        'https://example.com/3.png',
        'https://example.com/4.png',
    ]);

    expect($request->referenceImageCount())->toBe(4);
});

it('rejects requests Pixelforge would refuse', function (string $prompt, array $referenceImageUrls) {
    new GenerationRequest($prompt, ImageSize::Square, Quality::Medium, $referenceImageUrls);
})->with([
    'empty prompt' => ['', []],
    'whitespace prompt' => ["  \n ", []],
    '4001 characters' => [str_repeat('a', 4001), []],
    'five reference images' => ['a lighthouse', array_fill(0, 5, 'https://example.com/a.png')],
    'not a url' => ['a lighthouse', ['not a url']],
    'ftp url' => ['a lighthouse', ['ftp://example.com/a.png']],
    'javascript url' => ['a lighthouse', ['javascript:alert(1)']],
])->throws(InvalidGenerationRequest::class);
