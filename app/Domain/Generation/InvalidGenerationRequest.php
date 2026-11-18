<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use InvalidArgumentException;

final class InvalidGenerationRequest extends InvalidArgumentException
{
    public static function emptyPrompt(): self
    {
        return new self('The prompt cannot be empty.');
    }

    public static function promptTooLong(int $characters): self
    {
        return new self("The prompt has {$characters} characters; the maximum is ".GenerationRequest::MAX_PROMPT_CHARACTERS.'.');
    }

    public static function tooManyReferenceImages(int $count): self
    {
        return new self("{$count} reference images given; the maximum is ".GenerationRequest::MAX_REFERENCE_IMAGES.'.');
    }

    public static function invalidReferenceImageUrl(string $url): self
    {
        return new self("Reference image \"{$url}\" is not an http(s) URL.");
    }
}
