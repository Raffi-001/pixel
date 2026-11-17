<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Support\Arr;
use UnexpectedValueException;

/**
 * Pixelforge's published rates and token rules, from GET /v1/pricing.
 */
final readonly class PixelforgePricing
{
    /**
     * @param  array<string, string>  $rates  US dollars per 1M tokens, keyed by token type
     * @param  array<array-key, mixed>  $tokens
     */
    public function __construct(
        public array $rates,
        private array $tokens,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rates = [];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            $rate = Arr::get($data, 'rates.'.$type);
            throw_unless(is_string($rate) && is_numeric($rate), UnexpectedValueException::class, "Pixelforge pricing is missing the {$type} rate.");
            $rates[$type] = $rate;
        }

        $tokens = Arr::get($data, 'tokens');
        throw_unless(is_array($tokens), UnexpectedValueException::class, 'Pixelforge pricing is missing token rules.');

        return new self($rates, $tokens);
    }

    public function charactersPerTextToken(): int
    {
        return $this->int('characters_per_text_token');
    }

    public function textTokenVariance(): string
    {
        $value = Arr::get($this->tokens, 'text_token_variance');

        return is_numeric($value) ? (string) $value : '0';
    }

    public function tokensPerReferenceImage(): int
    {
        return $this->int('per_reference_image');
    }

    public function outputTokens(GenerationQuality $quality, GenerationSize $size): int
    {
        return $this->int("output.{$quality->value}.{$size->value}");
    }

    public function minReasoningTokens(GenerationQuality $quality): int
    {
        return $this->int("reasoning.{$quality->value}.min");
    }

    public function maxReasoningTokens(GenerationQuality $quality): int
    {
        return $this->int("reasoning.{$quality->value}.max");
    }

    private function int(string $key): int
    {
        $value = Arr::get($this->tokens, $key);
        throw_unless(is_int($value), UnexpectedValueException::class, "Pixelforge pricing is missing tokens.{$key}.");

        return $value;
    }
}
