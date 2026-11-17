<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Checks the Pixelforge-Signature header (t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">) and rejects stale timestamps.
 */
final readonly class VerifyPixelforgeSignature
{
    public function handle(string $payload, string $header, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', mb_trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature);
    }
}
