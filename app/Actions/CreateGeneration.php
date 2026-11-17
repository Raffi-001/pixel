<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a generation, holds its worst-case price from the user's balance and queues it for Pixelforge.
 */
final readonly class CreateGeneration
{
    public function __construct(
        private EstimateGenerationCost $estimateCost,
        private RecordCreditTransaction $recordTransaction,
        private AppSettings $settings,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws ValidationException
     */
    public function handle(
        User $user,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages = [],
        ?Generation $retryOf = null,
    ): Generation {
        if (! $this->settings->acceptingGenerations()) {
            throw ValidationException::withMessages(['prompt' => 'Generating images is paused right now. Please try again later.']);
        }

        $estimate = $this->estimateCost->handle($prompt, $size, $quality, $referenceImages);
        $hold = $estimate->maxPriceNano();

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $retryOf, $estimate, $hold): Generation {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->balance_nano < $hold) {
                throw ValidationException::withMessages([
                    'balance' => 'This generation can cost up to '.Money::format($hold).', but your balance is '.Money::format($locked->balance_nano).'. Buy credits to continue.',
                ]);
            }

            $generation = Generation::query()->create([
                'user_id' => $locked->id,
                'retry_of_id' => $retryOf?->id,
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'markup_bps' => $estimate->markupBps,
                'estimate_rates' => $estimate->rates,
                'estimated_min_nano' => $estimate->minPriceNano(),
                'estimated_max_nano' => $hold,
                'held_nano' => $hold,
            ]);

            $this->recordTransaction->handle($user, CreditTransactionType::Hold, -$hold, 'Held for a generation (maximum possible price)', $generation);

            return $generation;
        });

        dispatch(new SubmitGenerationJob($generation));

        return $generation;
    }
}
