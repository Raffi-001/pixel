<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Money;
use App\Services\Pixelforge\PixelforgeGeneration;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Brings our record in line with Pixelforge's view of a generation, from a webhook, a poll or the create response.
 * Safe to call any number of times, in any order: statuses only move forward, and money is settled exactly once,
 * on the single transition into a final status.
 */
final readonly class ApplyPixelforgeGeneration
{
    public function __construct(
        private CalculateProviderCost $calculateCost,
        private FinalizeGeneration $finalize,
    ) {}

    public function handle(PixelforgeGeneration $remote): ?Generation
    {
        return DB::transaction(function () use ($remote): ?Generation {
            $generation = $this->find($remote);

            if (! $generation instanceof Generation) {
                return null;
            }

            if ($generation->provider_id === null) {
                $generation->forceFill(['provider_id' => $remote->id, 'submitted_at' => $generation->submitted_at ?? now()]);
            }

            if ($remote->rates !== []) {
                $generation->forceFill(['rates' => $remote->rates]);
            }

            if ($remote->imageUrl !== null) {
                $generation->forceFill(['image_url' => $remote->imageUrl, 'image_url_expires_at' => $remote->imageUrlExpiresAt]);
            }

            if (! $generation->status->canTransitionTo($remote->status)) {
                $generation->save();

                return $generation;
            }

            $generation->forceFill([
                'status' => $remote->status,
                'started_at' => $remote->startedAt ?? $generation->started_at,
                'completed_at' => $remote->completedAt,
            ]);

            if ($remote->status === GenerationStatus::Succeeded) {
                $usage = $remote->usage;
                throw_if($usage === null, UnexpectedValueException::class, "Pixelforge generation {$remote->id} succeeded without usage.");
                $providerCost = $this->calculateCost->handle($usage, $remote->rates);

                $generation->forceFill([
                    ...$usage,
                    'provider_cost_nano' => $providerCost,
                    'charged_nano' => Money::withMarkup($providerCost, $generation->markup_bps),
                ]);
            }

            if ($remote->status === GenerationStatus::Failed) {
                $generation->forceFill([
                    'error_code' => $remote->errorCode ?? 'generation_failed',
                    'error_message' => $remote->errorMessage ?? 'The generation failed.',
                ]);
            }

            $generation->save();

            if ($remote->status->isFinal()) {
                $this->finalize->handle($generation);
            }

            return $generation;
        });
    }

    /**
     * Webhooks can arrive before our create request has returned, so we match on our own id in the metadata first.
     */
    private function find(PixelforgeGeneration $remote): ?Generation
    {
        $localId = $remote->metadata['generation_id'] ?? null;

        $query = Generation::query()->lockForUpdate();

        return $localId !== null
            ? $query->whereKey($localId)->first()
            : $query->where('provider_id', $remote->id)->first();
    }
}
