<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => null,
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'markup_bps' => 2500,
            'estimate_rates' => self::RATES,
            'estimated_min_nano' => 50_000_000,
            'estimated_max_nano' => 90_000_000,
            'held_nano' => 90_000_000,
        ];
    }

    public function submitted(GenerationStatus $status = GenerationStatus::Queued): self
    {
        return $this->state(fn (array $attributes): array => [
            'provider_id' => 'gen_'.fake()->regexify('[A-Z0-9]{26}'),
            'status' => $status,
            'rates' => self::RATES,
            'submitted_at' => now(),
        ]);
    }

    public function succeeded(int $providerCostNano = 60_000_000, ?int $chargedNano = null): self
    {
        return $this->submitted(GenerationStatus::Succeeded)->state(fn (array $attributes): array => [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1000,
            'provider_cost_nano' => $providerCostNano,
            'charged_nano' => $chargedNano ?? intdiv($providerCostNano * 12_500 + 9_999, 10_000),
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $code = 'generation_failed', string $message = 'The model could not produce an image.'): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
