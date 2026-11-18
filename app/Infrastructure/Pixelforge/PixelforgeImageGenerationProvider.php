<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\PricingSchedule;
use App\Domain\Generation\GenerationFailure;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ProviderUpdate;
use Closure;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pixelforge adapter: sends requests and translates every error into "retry later"
 * (ProviderUnavailable) or "definitively refused, nothing billed" (ProviderRefused).
 */
final readonly class PixelforgeImageGenerationProvider implements ImageGenerationProvider
{
    private const string PRICING_CACHE_KEY = 'pixelforge.pricing';

    /** A lookup scans at most this many list pages (requests) before trying again next round. */
    public const int LOOKUP_MAX_PAGES = 3;

    public const string LOOKUP_INCOMPLETE = 'lookup_incomplete';

    private const int LOOKUP_PAGE_SIZE = 100;

    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeoutSeconds,
        private int $pricingCacheSeconds,
        private PixelforgePayloadMapper $mapper,
    ) {}

    public function submit(string $generationId, GenerationRequest $request): ProviderUpdate
    {
        $body = [
            'prompt' => $request->prompt,
            'size' => $request->size->value,
            'quality' => $request->quality->value,
            'metadata' => ['generation_id' => $generationId],
        ];

        if ($request->referenceImageUrls !== []) {
            $body['reference_images'] = $request->referenceImageUrls;
        }

        $response = $this->send(fn (): Response => $this->authenticated()
            ->withHeaders(['Idempotency-Key' => $generationId])
            ->post('generations', $body));

        return $this->mapper->toProviderUpdate($this->json($response));
    }

    public function fetch(string $providerId): ProviderUpdate
    {
        $response = $this->send(fn (): Response => $this->authenticated()->get('generations/'.rawurlencode($providerId)));

        return $this->mapper->toProviderUpdate($this->json($response));
    }

    public function findSubmitted(string $generationId, DateTimeImmutable $submittedAfter): ?ProviderUpdate
    {
        $cursor = null;

        for ($page = 1; $page <= self::LOOKUP_MAX_PAGES; $page++) {
            $response = $this->send(fn (): Response => $this->authenticated()->get('generations', array_filter([
                'limit' => self::LOOKUP_PAGE_SIZE,
                'cursor' => $cursor,
            ])));
            $list = $this->json($response);

            foreach (is_array($list['data'] ?? null) ? $list['data'] : [] as $generation) {
                if (! is_array($generation)) {
                    continue;
                }

                if (is_array($generation['metadata'] ?? null) && ($generation['metadata']['generation_id'] ?? null) === $generationId) {
                    return $this->mapper->toProviderUpdate($generation);
                }

                // Newest first: once we pass the submission time, it was never received.
                if ($this->createdAt($generation) < $submittedAfter) {
                    return null;
                }
            }

            if (($list['has_more'] ?? false) !== true || ! is_string($list['next_cursor'] ?? null)) {
                return null;
            }

            $cursor = $list['next_cursor'];
        }

        throw new ProviderUnavailable(self::LOOKUP_INCOMPLETE);
    }

    public function pricing(): PricingSchedule
    {
        /** @var array<mixed> $payload */
        $payload = Cache::remember(
            self::PRICING_CACHE_KEY,
            $this->pricingCacheSeconds,
            fn (): array => $this->json($this->send(fn (): Response => $this->client()->get('pricing'))),
        );

        return $this->mapper->toPricingSchedule($payload);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(5);
    }

    private function authenticated(): PendingRequest
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            Log::critical('Pixelforge API key is not configured (PIXELFORGE_API_KEY).');

            throw new ProviderRefused(new GenerationFailure(
                GenerationFailure::PROVIDER_REJECTED_REQUEST,
                'Image generation is not configured yet. You were not charged.',
            ));
        }

        return $this->client()->withToken($this->apiKey);
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailable('connection_failed', outcomeUnknown: true, previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->errorFor($response);
    }

    private function errorFor(Response $response): ProviderUnavailable|ProviderRefused
    {
        $status = $response->status();
        $code = is_string($response->json('error.code')) ? $response->json('error.code') : "http_{$status}";
        $message = is_string($response->json('error.message')) ? $response->json('error.message') : 'Unexpected response from Pixelforge.';

        if ($code === 'daily_limit_exceeded') {
            return new ProviderRefused(new GenerationFailure($code, 'Pixelforge\'s daily generation limit is used up. Try again after midnight UTC. You were not charged.'));
        }

        if ($status === 429 || $code === 'overloaded') {
            // Explicit "busy" answers: Pixelforge documents that nothing was created.
            return new ProviderUnavailable($code, $this->retryAfterSeconds($response));
        }

        if ($status >= 500) {
            // Any other server error: we cannot tell whether the request was acted on.
            return new ProviderUnavailable($code, $this->retryAfterSeconds($response), outcomeUnknown: true);
        }

        if ($code === 'content_policy_violation') {
            return new ProviderRefused(new GenerationFailure($code, $message));
        }

        Log::error('Pixelforge rejected a request', [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'errors' => $response->json('error.errors'),
        ]);

        return new ProviderRefused(new GenerationFailure(
            GenerationFailure::PROVIDER_REJECTED_REQUEST,
            'Pixelforge could not accept this request. You were not charged.',
        ));
    }

    /**
     * @param  array<mixed>  $generation
     */
    private function createdAt(array $generation): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable(is_string($generation['created_at'] ?? null) ? $generation['created_at'] : throw MalformedProviderPayload::because('generation has no created_at'));
        } catch (DateMalformedStringException $exception) {
            throw MalformedProviderPayload::because('unreadable created_at', $exception);
        }
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : throw new RuntimeException('Pixelforge returned a non-JSON response.');
    }
}
