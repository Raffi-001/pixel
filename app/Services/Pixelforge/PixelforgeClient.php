<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class PixelforgeClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $parameters
     */
    public function createGeneration(array $parameters, string $idempotencyKey): PixelforgeGeneration
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('generations', $parameters));

        return PixelforgeGeneration::fromArray($this->json($response));
    }

    public function getGeneration(string $id): PixelforgeGeneration
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('generations/'.$id));

        return PixelforgeGeneration::fromArray($this->json($response));
    }

    /**
     * Pricing changes rarely and needs no API key, so it is cached briefly.
     */
    public function pricing(): PixelforgePricing
    {
        /** @var array<array-key, mixed> $data */
        $data = Cache::remember('pixelforge.pricing', now()->addMinutes(5), fn (): array => $this->json(
            $this->send(fn (PendingRequest $request): Response => $request->get('pricing')),
        ));

        return PixelforgePricing::fromArray($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function usage(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->json($this->send(fn (PendingRequest $request): Response => $request->get('usage', [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ])));
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     */
    private function send(callable $callback): Response
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);

        try {
            $response = $callback($request);
        } catch (ConnectionException $connectionException) {
            throw new PixelforgeException($connectionException->getMessage(), 'connection_failed', 0);
        }

        if ($response->failed()) {
            $code = $response->json('error.code');
            $message = $response->json('error.message');
            $retryAfter = $response->header('Retry-After');

            throw new PixelforgeException(
                is_string($message) ? $message : 'Pixelforge returned HTTP '.$response->status().'.',
                is_string($code) ? $code : 'http_'.$response->status(),
                $response->status(),
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
