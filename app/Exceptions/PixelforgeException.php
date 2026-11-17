<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * Rejections caused by Pixelforge's per-account limits. Nothing was created, so the request can wait in our queue.
     */
    public function isTemporaryLimit(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true);
    }

    /**
     * No response was received, so we cannot tell whether the generation was created.
     */
    public function isConnectionFailure(): bool
    {
        return $this->errorCode === 'connection_failed';
    }
}
