<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Fails a generation that Pixelforge never accepted (or that we gave up waiting on). It costs nothing.
 */
final readonly class FailGeneration
{
    public function __construct(private FinalizeGeneration $finalize) {}

    public function handle(Generation $generation, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($generation, $errorCode, $errorMessage): void {
            /** @var Generation $locked */
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if (! $locked->status->canTransitionTo(GenerationStatus::Failed)) {
                return;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ])->save();

            $this->finalize->handle($locked);
        });
    }
}
