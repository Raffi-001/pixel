<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Explains the difference, if any, between what Pixelforge billed on one UTC day and what we recorded,
 * generation by generation. GET /v1/usage only gives daily totals; this pages through GET /v1/generations.
 */
final readonly class ReconcileProviderDay
{
    /** Pages of 100 generations; keeps one reconciliation well inside the 60 requests per minute limit. */
    public const int MAX_PAGES = 20;

    public function __construct(
        private PixelforgeClient $pixelforge,
        private CalculateProviderCost $calculateCost,
    ) {}

    public static function describe(string $verdict): string
    {
        return match ($verdict) {
            'missing_locally' => 'Pixelforge billed a generation we have no record of',
            'cost_mismatch' => 'We recorded a different cost than Pixelforge',
            'not_charged' => 'Pixelforge billed it, but we marked it failed and did not charge the user',
            'out_of_date' => 'Pixelforge finished it; our record has not caught up yet',
            'not_billed' => 'We recorded a cost Pixelforge did not bill on this day',
            default => $verdict,
        };
    }

    /**
     * @return array{
     *     available: bool,
     *     complete: bool,
     *     pixelforge_cost_nano: int,
     *     our_cost_nano: int,
     *     matched: int,
     *     rows: list<array{provider_id: string, prompt: string, user: string|null, pixelforge_status: string, pixelforge_cost_nano: int|null, our_status: string|null, our_cost_nano: int|null, verdict: string, can_resync: bool}>
     * }
     */
    public function handle(CarbonImmutable $date): array
    {
        $day = $date->utc()->startOfDay();
        $remote = $this->remoteGenerations($day);

        if ($remote === null) {
            return ['available' => false, 'complete' => false, 'pixelforge_cost_nano' => 0, 'our_cost_nano' => 0, 'matched' => 0, 'rows' => []];
        }

        $billed = [];
        $outOfDate = [];

        foreach ($remote['generations'] as $data) {
            $generation = PixelforgeGeneration::fromArray($data);

            if ($generation->status === GenerationStatus::Succeeded && $generation->completedAt?->isSameDay($day)) {
                $billed[$generation->id] = $generation;
            } elseif ($generation->status->isFinal()) {
                $outOfDate[$generation->id] = $generation;
            }
        }

        $local = Generation::query()
            ->with('user:id,email')
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('provider_id', array_keys($billed + $outOfDate))
                ->orWhereIn('id', array_filter(array_map(fn (PixelforgeGeneration $generation): ?string => $generation->metadata['generation_id'] ?? null, array_values($billed)))))
            ->orWhere(fn (Builder $query): Builder => $query
                ->where('status', GenerationStatus::Succeeded)
                ->whereBetween('completed_at', [$day, $day->endOfDay()]))
            ->get();

        $byProviderId = $local->whereNotNull('provider_id')->keyBy('provider_id');
        $byId = $local->keyBy('id');

        $rows = [];
        $matched = 0;
        $pixelforgeCost = 0;

        foreach ($billed as $providerId => $generation) {
            $cost = $this->calculateCost->handle($generation->usage ?? ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], $generation->rates);
            $pixelforgeCost += $cost;
            $ours = $byProviderId->get($providerId) ?? $byId->get($generation->metadata['generation_id'] ?? '');

            $verdict = match (true) {
                ! $ours instanceof Generation => 'missing_locally',
                $ours->status === GenerationStatus::Succeeded && $ours->provider_cost_nano === $cost => 'match',
                $ours->status === GenerationStatus::Succeeded => 'cost_mismatch',
                $ours->status === GenerationStatus::Failed => 'not_charged',
                default => 'out_of_date',
            };

            if ($verdict === 'match') {
                $matched++;

                continue;
            }

            $rows[] = $this->row($providerId, $generation, $cost, $ours, $verdict);
        }

        foreach ($outOfDate as $providerId => $generation) {
            $ours = $byProviderId->get($providerId);

            if ($ours instanceof Generation && ! $ours->status->isFinal()) {
                $rows[] = $this->row($providerId, $generation, null, $ours, 'out_of_date');
            }
        }

        $ourCost = 0;

        foreach ($local as $generation) {
            if ($generation->status !== GenerationStatus::Succeeded || ! $generation->completed_at?->isSameDay($day)) {
                continue;
            }

            $ourCost += $generation->provider_cost_nano ?? 0;

            if ($generation->provider_id === null || ! isset($billed[$generation->provider_id])) {
                $rows[] = [
                    'provider_id' => $generation->provider_id ?? '—',
                    'prompt' => $generation->prompt,
                    'user' => $generation->user->email,
                    'pixelforge_status' => 'not billed this day',
                    'pixelforge_cost_nano' => null,
                    'our_status' => $generation->status->value,
                    'our_cost_nano' => $generation->provider_cost_nano,
                    'verdict' => 'not_billed',
                    'can_resync' => false,
                ];
            }
        }

        return [
            'available' => true,
            'complete' => $remote['complete'],
            'pixelforge_cost_nano' => $pixelforgeCost,
            'our_cost_nano' => $ourCost,
            'matched' => $matched,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{provider_id: string, prompt: string, user: string|null, pixelforge_status: string, pixelforge_cost_nano: int|null, our_status: string|null, our_cost_nano: int|null, verdict: string, can_resync: bool}
     */
    private function row(string $providerId, PixelforgeGeneration $generation, ?int $cost, ?Generation $ours, string $verdict): array
    {
        return [
            'provider_id' => $providerId,
            'prompt' => $ours->prompt ?? '',
            'user' => $ours?->user->email,
            'pixelforge_status' => $generation->status->value,
            'pixelforge_cost_nano' => $cost,
            'our_status' => $ours?->status->value,
            'our_cost_nano' => $ours?->provider_cost_nano,
            'verdict' => $verdict,
            'can_resync' => in_array($verdict, ['missing_locally', 'out_of_date'], true),
        ];
    }

    /**
     * Generations are listed newest first, so we page back until they were created before the day began
     * (with a day's margin for anything still running at midnight). Cached briefly to spare the rate limit.
     *
     * @return array{generations: list<array<array-key, mixed>>, complete: bool}|null
     */
    private function remoteGenerations(CarbonImmutable $day): ?array
    {
        $cutoff = $day->subDay();

        try {
            /** @var array{generations: list<array<array-key, mixed>>, complete: bool} */
            return Cache::remember('pixelforge.reconcile.'.$day->toDateString(), now()->addMinute(), function () use ($cutoff): array {
                $generations = [];
                $cursor = null;

                for ($page = 0; $page < self::MAX_PAGES; $page++) {
                    $response = $this->pixelforge->listGenerations($cursor);
                    $data = Arr::get($response, 'data');

                    foreach (is_array($data) ? $data : [] as $generation) {
                        if (! is_array($generation)) {
                            continue;
                        }

                        $createdAt = Arr::get($generation, 'created_at');

                        if (is_string($createdAt) && CarbonImmutable::parse($createdAt)->lessThan($cutoff)) {
                            return ['generations' => $generations, 'complete' => true];
                        }

                        $generations[] = $generation;
                    }

                    $cursor = Arr::get($response, 'next_cursor');

                    if (Arr::get($response, 'has_more') !== true || ! is_string($cursor)) {
                        return ['generations' => $generations, 'complete' => true];
                    }
                }

                return ['generations' => $generations, 'complete' => false];
            });
        } catch (PixelforgeException) {
            return null;
        }
    }
}
