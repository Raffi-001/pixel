<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The range a generation can cost. Text and reasoning token counts are only known once it completes.
 */
final readonly class CostEstimate
{
    /**
     * @param  array<string, string>  $rates
     */
    public function __construct(
        public array $rates,
        public int $markupBps,
        public int $minInputTextTokens,
        public int $maxInputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $minReasoningTokens,
        public int $maxReasoningTokens,
        public int $minProviderCostNano,
        public int $maxProviderCostNano,
    ) {}

    public function minPriceNano(): int
    {
        return Money::withMarkup($this->minProviderCostNano, $this->markupBps);
    }

    /**
     * The most this generation can cost the user. This is what we hold from their balance.
     */
    public function maxPriceNano(): int
    {
        return Money::withMarkup($this->maxProviderCostNano, $this->markupBps);
    }

    /**
     * @return array<string, int|string|array<string, string>>
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'markup_percent' => Money::formatBps($this->markupBps),
            'tokens' => [
                'input_text' => $this->minInputTextTokens === $this->maxInputTextTokens
                    ? (string) $this->maxInputTextTokens
                    : $this->minInputTextTokens.'–'.$this->maxInputTextTokens,
                'input_image' => (string) $this->inputImageTokens,
                'output' => (string) $this->outputTokens,
                'reasoning' => $this->minReasoningTokens.'–'.$this->maxReasoningTokens,
            ],
            'min_price_nano' => $this->minPriceNano(),
            'max_price_nano' => $this->maxPriceNano(),
            'min_price' => Money::format($this->minPriceNano()),
            'max_price' => Money::format($this->maxPriceNano()),
        ];
    }
}
