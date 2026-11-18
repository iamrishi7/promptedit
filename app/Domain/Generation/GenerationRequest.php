<?php

declare(strict_types=1);

namespace App\Domain\Generation;

/**
 * What a user asked to generate, validated against Pixelforge's limits.
 */
final readonly class GenerationRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @param  list<string>  $referenceImageUrls
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public Quality $quality,
        public array $referenceImageUrls = [],
    ) {
        if (trim($prompt) === '') {
            throw InvalidGenerationRequest::emptyPrompt();
        }

        if ($this->promptCharacterCount() > self::MAX_PROMPT_CHARACTERS) {
            throw InvalidGenerationRequest::promptTooLong($this->promptCharacterCount());
        }

        if (count($referenceImageUrls) > self::MAX_REFERENCE_IMAGES) {
            throw InvalidGenerationRequest::tooManyReferenceImages(count($referenceImageUrls));
        }

        foreach ($referenceImageUrls as $url) {
            if (! self::isHttpUrl($url)) {
                throw InvalidGenerationRequest::invalidReferenceImageUrl($url);
            }
        }
    }

    public function equals(self $other): bool
    {
        return $this->prompt === $other->prompt
            && $this->size === $other->size
            && $this->quality === $other->quality
            && $this->referenceImageUrls === $other->referenceImageUrls;
    }

    /**
     * Prompt length in Unicode characters (not bytes), which is how Pixelforge counts text tokens.
     */
    public function promptCharacterCount(): int
    {
        return mb_strlen($this->prompt, 'UTF-8');
    }

    public function referenceImageCount(): int
    {
        return count($this->referenceImageUrls);
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
