<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\PixelforgeException;
use App\Services\Money;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * What Pixelforge says it billed this API key per UTC day (GET /v1/usage), to reconcile against our records.
 */
final readonly class FetchProviderUsage
{
    public const int MAX_DAYS = 92;

    public function __construct(private PixelforgeClient $pixelforge) {}

    /**
     * @return array<string, array{generations: int, cost_nano: int}>|null null when Pixelforge can't be reached
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            /** @var array<array-key, mixed> $response */
            $response = Cache::remember(
                'pixelforge.usage.'.$from->toDateString().'.'.$to->toDateString(),
                now()->addMinute(),
                fn (): array => $this->pixelforge->usage($from, $to),
            );
        } catch (PixelforgeException) {
            return null;
        }

        $data = Arr::get($response, 'data');
        $usage = [];

        foreach (is_array($data) ? $data : [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            $date = Arr::get($day, 'date');
            $generations = Arr::get($day, 'generations');
            $cost = Arr::get($day, 'cost_usd');

            if (is_string($date) && is_int($generations) && is_string($cost)) {
                $usage[$date] = ['generations' => $generations, 'cost_nano' => Money::fromDecimal($cost)];
            }
        }

        return $usage;
    }
}
