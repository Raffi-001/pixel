<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\PixelforgeCapacity;
use Illuminate\Support\Facades\Config;

/**
 * Sends a waiting generation to Pixelforge. Our generation id doubles as the idempotency key,
 * so resending after a lost response can never create (or bill) a second generation.
 */
final readonly class SubmitGeneration
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private ApplyPixelforgeGeneration $applyRemote,
        private FailGeneration $failGeneration,
        private PixelforgeCapacity $capacity,
    ) {}

    /**
     * @throws PixelforgeException when Pixelforge is at its limits or unreachable, so the caller can try again later
     */
    public function handle(Generation $generation): void
    {
        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $remote = $this->pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id, 'user_id' => $generation->user_id, 'app_url' => Config::string('app.url')],
            ], idempotencyKey: $generation->id);
        } catch (PixelforgeException $pixelforgeException) {
            throw_if($pixelforgeException->isTemporaryLimit() || $pixelforgeException->isConnectionFailure(), $pixelforgeException);

            if ($pixelforgeException->errorCode === 'daily_limit_exceeded') {
                $this->capacity->pauseUntilNextUtcDay();
            }

            $this->failGeneration->handle($generation, $pixelforgeException->errorCode, $this->describe($pixelforgeException));

            return;
        }

        $this->applyRemote->handle($remote);
    }

    private function describe(PixelforgeException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'overloaded' => 'Pixelforge was overloaded and did not accept the request. You were not charged; please retry.',
            'daily_limit_exceeded' => $this->capacity->pausedMessage(),
            default => $exception->getMessage().' You were not charged.',
        };
    }
}
