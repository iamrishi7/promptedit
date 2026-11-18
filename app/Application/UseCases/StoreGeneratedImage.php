<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Exceptions\ImageUnavailable;
use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageArchive;
use App\Application\Ports\TransactionRunner;

/**
 * Copy a succeeded generation's image into our storage before Pixelforge's link expires. Safe to
 * run more than once: an image already stored is left alone, and the copy goes to a fixed path.
 * The download happens outside the transaction, so no row stays locked during a slow fetch.
 */
final readonly class StoreGeneratedImage
{
    public function __construct(
        private GenerationRepository $generations,
        private ImageArchive $archive,
        private TransactionRunner $transactions,
    ) {}

    /**
     * @throws ImageUnavailable
     */
    public function handle(string $generationId): void
    {
        $generation = $this->generations->find($generationId);
        $sourceUrl = $generation?->imageUrl();

        if ($generation === null || $sourceUrl === null || ! $generation->needsImageStored()) {
            return;
        }

        try {
            $path = $this->archive->store($generation->id, $generation->userId, $sourceUrl);
        } catch (ImageUnavailable $unavailable) {
            if ($unavailable->permanent) {
                $this->generations->markImageUnstorable($generationId);
            }

            throw $unavailable;
        }

        $this->transactions->run(function () use ($generationId, $path): void {
            $locked = $this->generations->findForUpdate($generationId);

            if ($locked?->recordStoredImage($path)) {
                $this->generations->save($locked);
            }
        });
    }
}
