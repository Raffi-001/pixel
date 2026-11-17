<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonImmutable;

/**
 * Credit spend (what users paid), provider cost (what Pixelforge charged) and profit, per UTC day and per user.
 * Like Pixelforge's GET /v1/usage, a generation counts on the UTC day it completed, and only successes cost anything.
 */
final readonly class BuildSpendReport
{
    private const array EMPTY = ['generations' => 0, 'provider_cost_nano' => 0, 'charged_nano' => 0, 'profit_nano' => 0];

    /**
     * @return array{
     *     days: list<array{date: string, generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}>,
     *     users: list<array{date: string, user_id: string, name: string, email: string, generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}>,
     *     totals: array{generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}
     * }
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->utc()->startOfDay();
        $to = $to->utc()->endOfDay();

        /** @var array<string, array{generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}> $dayMetrics */
        $dayMetrics = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $dayMetrics[$day->toDateString()] = self::EMPTY;
        }

        $userMetrics = [];
        $userDetails = [];
        $totals = self::EMPTY;

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$from, $to])
            ->lazyById(500);

        foreach ($generations as $generation) {
            $date = $generation->completed_at?->utc()->toDateString() ?? '';
            $cost = $generation->provider_cost_nano ?? 0;
            $charged = $generation->charged_nano ?? 0;
            $key = $date.'|'.$generation->user_id;

            $userDetails[$key] = [
                'date' => $date,
                'user_id' => $generation->user_id,
                'name' => $generation->user->name,
                'email' => $generation->user->email,
            ];

            $dayMetrics[$date] = $this->add($dayMetrics[$date] ?? self::EMPTY, $cost, $charged);
            $userMetrics[$key] = $this->add($userMetrics[$key] ?? self::EMPTY, $cost, $charged);
            $totals = $this->add($totals, $cost, $charged);
        }

        $days = [];

        foreach (array_reverse($dayMetrics, preserve_keys: true) as $date => $metrics) {
            $days[] = ['date' => (string) $date, ...$metrics];
        }

        $users = [];

        foreach ($userMetrics as $key => $metrics) {
            $users[] = [...$userDetails[$key], ...$metrics];
        }

        usort($users, fn (array $a, array $b): int => [$b['date'], $b['charged_nano']] <=> [$a['date'], $a['charged_nano']]);

        return [
            'days' => $days,
            'users' => $users,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array{generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}  $row
     * @return array{generations: int, provider_cost_nano: int, charged_nano: int, profit_nano: int}
     */
    private function add(array $row, int $cost, int $charged): array
    {
        $row['generations']++;
        $row['provider_cost_nano'] += $cost;
        $row['charged_nano'] += $charged;
        $row['profit_nano'] += $charged - $cost;

        return $row;
    }
}
