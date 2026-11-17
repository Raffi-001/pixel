<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * A typed view of Pixelforge's generation object.
 */
final readonly class PixelforgeGeneration
{
    /**
     * @param  array<string, string>  $metadata
     * @param  array<string, string>  $rates
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}|null  $usage
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata,
        public array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $usage = Arr::get($data, 'usage');

        return new self(
            id: self::string($data, 'id') ?? '',
            status: GenerationStatus::from(self::string($data, 'status') ?? ''),
            metadata: self::stringMap(Arr::get($data, 'metadata')),
            rates: self::stringMap(Arr::get($data, 'rates')),
            usage: is_array($usage) ? [
                'input_text_tokens' => self::int($usage, 'input_text_tokens'),
                'input_image_tokens' => self::int($usage, 'input_image_tokens'),
                'output_tokens' => self::int($usage, 'output_tokens'),
                'reasoning_tokens' => self::int($usage, 'reasoning_tokens'),
            ] : null,
            imageUrl: self::string($data, 'image_url'),
            imageUrlExpiresAt: self::date($data, 'image_url_expires_at'),
            errorCode: self::string($data, 'error.code'),
            errorMessage: self::string($data, 'error.message'),
            startedAt: self::date($data, 'started_at'),
            completedAt: self::date($data, 'completed_at'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function int(array $data, string $key): int
    {
        $value = Arr::get($data, $key);

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        $value = self::string($data, $key);

        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }
}
