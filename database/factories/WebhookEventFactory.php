<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'evt_'.fake()->regexify('[A-Z0-9]{26}');

        return [
            'id' => $id,
            'type' => 'generation.succeeded',
            'provider_generation_id' => 'gen_'.fake()->regexify('[A-Z0-9]{26}'),
            'payload' => ['id' => $id],
        ];
    }
}
