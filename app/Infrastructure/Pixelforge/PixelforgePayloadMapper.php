<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use App\Application\Exceptions\MalformedProviderPayload;
use App\Domain\Billing\PricingSchedule;
use App\Domain\Billing\TokenRange;
use App\Domain\Billing\TokenRates;
use App\Domain\Billing\TokenUsage;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ProviderUpdate;
use Closure;
use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;
use ValueError;

/**
 * Translates Pixelforge JSON (generation objects, webhook data.object, /v1/pricing) into domain
 * objects. The only place that knows Pixelforge's field names.
 */
final class PixelforgePayloadMapper
{
    /**
     * @param  array<mixed>  $generation  a Pixelforge generation object
     *
     * @throws MalformedProviderPayload
     */
    public function toProviderUpdate(array $generation): ProviderUpdate
    {
        return $this->readable(fn (): ProviderUpdate => $this->buildProviderUpdate($generation));
    }

    /**
     * @param  array<mixed>  $pricing  the /v1/pricing response
     *
     * @throws MalformedProviderPayload
     */
    public function toPricingSchedule(array $pricing): PricingSchedule
    {
        return $this->readable(fn (): PricingSchedule => $this->buildPricingSchedule($pricing));
    }

    /**
     * Turn every way a payload can be unreadable into one exception callers can act on.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $build
     * @return TResult
     */
    private function readable(Closure $build): mixed
    {
        try {
            return $build();
        } catch (UnexpectedValueException|InvalidArgumentException|ValueError|DateMalformedStringException $exception) {
            throw MalformedProviderPayload::because($exception->getMessage(), $exception);
        }
    }

    /**
     * @param  array<mixed>  $generation
     */
    private function buildProviderUpdate(array $generation): ProviderUpdate
    {
        $status = GenerationStatus::tryFrom($this->string($generation, 'status'))
            ?? throw new UnexpectedValueException('Unknown Pixelforge generation status.');
        $metadata = $this->arrayOrNull($generation, 'metadata') ?? [];
        $usage = $this->arrayOrNull($generation, 'usage');
        $error = $this->arrayOrNull($generation, 'error');
        $succeeded = $status === GenerationStatus::Succeeded;

        return new ProviderUpdate(
            providerId: $this->string($generation, 'id'),
            status: $status,
            generationId: is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : null,
            usage: $succeeded && $usage !== null ? new TokenUsage(
                $this->int($usage, 'input_text_tokens'),
                $this->int($usage, 'input_image_tokens'),
                $this->int($usage, 'output_tokens'),
                $this->int($usage, 'reasoning_tokens'),
            ) : null,
            rates: $succeeded ? $this->rates($this->array($generation, 'rates')) : null,
            imageUrl: $this->stringOrNull($generation, 'image_url'),
            imageUrlExpiresAt: $this->timeOrNull($generation, 'image_url_expires_at'),
            failure: $error === null ? null : new GenerationFailure($this->string($error, 'code'), $this->string($error, 'message')),
            completedAt: $this->timeOrNull($generation, 'completed_at'),
        );
    }

    /**
     * @param  array<mixed>  $pricing
     */
    private function buildPricingSchedule(array $pricing): PricingSchedule
    {
        $tokens = $this->array($pricing, 'tokens');
        $output = $this->array($tokens, 'output');
        $reasoning = $this->array($tokens, 'reasoning');

        $outputTokens = [];
        foreach (array_keys($output) as $quality) {
            $sizes = $this->array($output, (string) $quality);

            foreach (array_keys($sizes) as $size) {
                $outputTokens[(string) $quality][(string) $size] = $this->int($sizes, (string) $size);
            }
        }

        $reasoningTokens = [];
        foreach (array_keys($reasoning) as $quality) {
            $range = $this->array($reasoning, (string) $quality);
            $reasoningTokens[(string) $quality] = new TokenRange($this->int($range, 'min'), $this->int($range, 'max'));
        }

        return new PricingSchedule(
            rates: $this->rates($this->array($pricing, 'rates')),
            charactersPerTextToken: $this->int($tokens, 'characters_per_text_token'),
            textTokenVarianceBasisPoints: $this->basisPoints($tokens, 'text_token_variance'),
            tokensPerReferenceImage: $this->int($tokens, 'per_reference_image'),
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * @param  array<mixed>  $rates
     */
    private function rates(array $rates): TokenRates
    {
        return TokenRates::fromDecimalStrings(
            $this->string($rates, 'input_text_tokens'),
            $this->string($rates, 'input_image_tokens'),
            $this->string($rates, 'output_tokens'),
            $this->string($rates, 'reasoning_tokens'),
        );
    }

    /**
     * Pixelforge sends the variance as a JSON number (0.15). Convert it via its decimal text, never
     * by multiplying a float, so 0.15 becomes exactly 1500 basis points.
     *
     * @param  array<mixed>  $data
     */
    private function basisPoints(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        $text = is_int($value) || is_float($value) || is_string($value) ? (string) $value : '';

        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', $text, $matches) !== 1) {
            throw new UnexpectedValueException("Pixelforge field {$key} is not a decimal with at most 4 places.");
        }

        return (int) ($matches[1].str_pad($matches[2] ?? '', 4, '0'));
    }

    /**
     * @param  array<mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : throw new UnexpectedValueException("Pixelforge field {$key} is missing or not a string.");
    }

    /**
     * @param  array<mixed>  $data
     */
    private function stringOrNull(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : $this->string($data, $key);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : throw new UnexpectedValueException("Pixelforge field {$key} is missing or not an integer.");
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function array(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : throw new UnexpectedValueException("Pixelforge field {$key} is missing or not an object.");
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|null
     */
    private function arrayOrNull(array $data, string $key): ?array
    {
        return ($data[$key] ?? null) === null ? null : $this->array($data, $key);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function timeOrNull(array $data, string $key): ?DateTimeImmutable
    {
        $value = $this->stringOrNull($data, $key);

        return $value === null ? null : new DateTimeImmutable($value);
    }
}
