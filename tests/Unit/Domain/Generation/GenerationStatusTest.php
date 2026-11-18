<?php

use App\Domain\Generation\GenerationStatus;

it('orders statuses along the Pixelforge lifecycle', function (GenerationStatus $earlier, GenerationStatus $later) {
    expect($earlier->isBefore($later))->toBeTrue()
        ->and($later->isBefore($earlier))->toBeFalse();
})->with([
    [GenerationStatus::Pending, GenerationStatus::Queued],
    [GenerationStatus::Queued, GenerationStatus::Processing],
    [GenerationStatus::Processing, GenerationStatus::Succeeded],
    [GenerationStatus::Processing, GenerationStatus::Failed],
    [GenerationStatus::Pending, GenerationStatus::Failed],
]);

it('treats only succeeded and failed as terminal', function (GenerationStatus $status, bool $terminal) {
    expect($status->isTerminal())->toBe($terminal);
})->with([
    [GenerationStatus::Pending, false],
    [GenerationStatus::Queued, false],
    [GenerationStatus::Processing, false],
    [GenerationStatus::Succeeded, true],
    [GenerationStatus::Failed, true],
]);
