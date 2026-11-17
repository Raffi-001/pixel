<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Log;

/**
 * Webhook delivery isn't guaranteed, so generations that have been in flight for a while are polled.
 */
final readonly class SyncPendingGenerations
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private ApplyPixelforgeGeneration $applyRemote,
    ) {}

    /**
     * @return int the number of generations checked
     */
    public function handle(int $staleAfterSeconds = 60, int $limit = 10): int
    {
        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        foreach ($generations as $generation) {
            try {
                $this->applyRemote->handle($this->pixelforge->getGeneration((string) $generation->provider_id));
            } catch (PixelforgeException $exception) {
                Log::warning('Could not poll Pixelforge generation.', ['generation' => $generation->id, 'error' => $exception->errorCode]);

                if ($exception->isTemporaryLimit()) {
                    break;
                }
            }

            $generation->touch();
        }

        return $generations->count();
    }
}
