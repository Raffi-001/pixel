<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\Money;

/**
 * What Pixelforge charged for a finished generation: its token usage at the rates fixed when it was created.
 */
final readonly class CalculateProviderCost
{
    /**
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     * @param  array<string, string>  $rates
     */
    public function handle(array $usage, array $rates): int
    {
        $cost = 0;

        foreach ($usage as $tokenType => $tokens) {
            $cost += Money::tokenCost($tokens, $rates[$tokenType] ?? '0');
        }

        return $cost;
    }
}
