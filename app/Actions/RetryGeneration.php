<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Validation\ValidationException;

/**
 * Starts a new generation with the same parameters as a failed one. We never retry on the user's behalf.
 * Each failed generation can be retried once; retrying it again returns that same retry.
 */
final readonly class RetryGeneration
{
    public function __construct(private CreateGeneration $createGeneration) {}

    /**
     * @throws ValidationException
     */
    public function handle(Generation $generation): Generation
    {
        if ($generation->status !== GenerationStatus::Failed) {
            throw ValidationException::withMessages(['generation' => 'Only failed generations can be retried.']);
        }

        return $this->createGeneration->handle(
            $generation->loadMissing('user')->user,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
            $generation,
            'retry:'.$generation->id,
        );
    }
}
