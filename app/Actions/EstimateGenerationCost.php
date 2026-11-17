<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\AppSettings;
use App\Services\CostEstimate;
use App\Services\Money;
use App\Services\Pixelforge\PixelforgeClient;
use BcMath\Number;

/**
 * Works out the cheapest and most expensive a generation can be, from Pixelforge's published pricing rules.
 */
final readonly class EstimateGenerationCost
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private AppSettings $settings,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function handle(string $prompt, GenerationSize $size, GenerationQuality $quality, array $referenceImages): CostEstimate
    {
        $pricing = $this->pixelforge->pricing();

        $estimatedTextTokens = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken());
        $variance = Money::number($pricing->textTokenVariance());
        $minTextTokens = (int) new Number($estimatedTextTokens)->mul(new Number(1)->sub($variance))->floor()->value;
        $maxTextTokens = (int) new Number($estimatedTextTokens)->mul(new Number(1)->add($variance))->ceil()->value;

        $imageTokens = count($referenceImages) * $pricing->tokensPerReferenceImage();
        $outputTokens = $pricing->outputTokens($quality, $size);
        $minReasoningTokens = $pricing->minReasoningTokens($quality);
        $maxReasoningTokens = $pricing->maxReasoningTokens($quality);

        return new CostEstimate(
            rates: $pricing->rates,
            markupBps: $this->settings->markupBps(),
            minInputTextTokens: $minTextTokens,
            maxInputTextTokens: $maxTextTokens,
            inputImageTokens: $imageTokens,
            outputTokens: $outputTokens,
            minReasoningTokens: $minReasoningTokens,
            maxReasoningTokens: $maxReasoningTokens,
            minProviderCostNano: $this->cost($pricing->rates, $minTextTokens, $imageTokens, $outputTokens, $minReasoningTokens),
            maxProviderCostNano: $this->cost($pricing->rates, $maxTextTokens, $imageTokens, $outputTokens, $maxReasoningTokens),
        );
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function cost(array $rates, int $text, int $image, int $output, int $reasoning): int
    {
        return Money::tokenCost($text, $rates['input_text_tokens'])
            + Money::tokenCost($image, $rates['input_image_tokens'])
            + Money::tokenCost($output, $rates['output_tokens'])
            + Money::tokenCost($reasoning, $rates['reasoning_tokens']);
    }
}
