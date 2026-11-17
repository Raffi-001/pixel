<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    /** Accepted by us, waiting in our queue for a free Pixelforge slot. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses only ever move forward: a late or replayed event can never move a generation back.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
