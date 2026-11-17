<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\AppSettings;
use App\Services\PixelforgeCapacity;
use Illuminate\Support\Collection;

/**
 * Tells users why their waiting generations are waiting: their place in line, their own running
 * generations, or Pixelforge's daily limit. Adds queue_position and waiting_reason to waiting generations.
 */
final readonly class DescribeQueuePositions
{
    public function __construct(
        private AppSettings $settings,
        private PixelforgeCapacity $capacity,
    ) {}

    /**
     * @param  Collection<int, Generation>  $generations
     */
    public function handle(Collection $generations): void
    {
        $waiting = $generations->filter(fn (Generation $generation): bool => $generation->status === GenerationStatus::Pending);

        if ($waiting->isEmpty()) {
            return;
        }

        $line = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->oldest()
            ->oldest('id')
            ->pluck('id')
            ->flip();

        $runningByUser = Generation::query()
            ->whereIn('user_id', $waiting->pluck('user_id')->unique())
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->pluck('user_id')
            ->countBy();

        foreach ($waiting as $generation) {
            $position = $line[$generation->id] ?? null;
            $running = $runningByUser->get($generation->user_id, 0);

            $generation->setAttribute('queue_position', is_int($position) ? $position + 1 : null);
            $generation->setAttribute('waiting_reason', match (true) {
                $this->capacity->isPaused() => 'Paused until 00:00 UTC (Pixelforge daily limit)',
                $running >= $this->settings->maxInFlightPerUser() => 'Waiting for your other generations to finish',
                is_int($position) && $position > 0 => $position.' ahead in line',
                default => 'Next in line',
            });
        }
    }
}
