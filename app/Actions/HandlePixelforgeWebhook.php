<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeGeneration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Records a verified webhook event once and applies it. A redelivered event id is acknowledged and ignored.
 * Recording and applying share a transaction, so if applying fails the event is not marked as seen and Pixelforge's retry is processed.
 */
final readonly class HandlePixelforgeWebhook
{
    public function __construct(private ApplyPixelforgeGeneration $applyRemote) {}

    /**
     * @param  array<array-key, mixed>  $event
     */
    public function handle(array $event): void
    {
        $eventId = Arr::get($event, 'id');
        $type = Arr::get($event, 'type');
        $object = Arr::get($event, 'data.object');

        if (! is_string($eventId) || ! is_string($type) || ! str_starts_with($type, 'generation.') || ! is_array($object)) {
            return;
        }

        DB::transaction(function () use ($eventId, $type, $object, $event): void {
            $isNew = WebhookEvent::query()->insertOrIgnore([
                'id' => $eventId,
                'type' => $type,
                'provider_generation_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if ($isNew) {
                $this->applyRemote->handle(PixelforgeGeneration::fromArray($object));
            }
        });
    }
}
