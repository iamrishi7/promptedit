<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows the public pages to guests', function (string $uri, string $component) {
    $this->get($uri)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'home' => ['/', 'Welcome'],
    'explore' => ['/explore', 'Explore'],
    'quality and sizes' => ['/models', 'Models'],
]);
