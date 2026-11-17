<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Http\Requests\CreateGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class GenerationController
{
    public const int RECENT_LIMIT = 8;

    /**
     * History filters, each covering one or more statuses.
     *
     * @var array<string, array{label: string, statuses: list<GenerationStatus>}>
     */
    private const array FILTERS = [
        'in_progress' => ['label' => 'In progress', 'statuses' => [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]],
        'succeeded' => ['label' => 'Succeeded', 'statuses' => [GenerationStatus::Succeeded]],
        'failed' => ['label' => 'Failed', 'statuses' => [GenerationStatus::Failed]],
    ];

    public function create(#[CurrentUser] User $user): Response
    {
        $recent = $user->generations()->latest()->limit(self::RECENT_LIMIT)->get();

        return Inertia::render('generations/Create', [
            'generations' => GenerationResource::collection($recent),
            'hasMore' => $user->generations()->count() > self::RECENT_LIMIT,
            'sizes' => array_map(fn (GenerationSize $size): array => ['value' => $size->value, 'label' => $size->label()], GenerationSize::cases()),
            'qualities' => array_map(fn (GenerationQuality $quality): array => ['value' => $quality->value, 'label' => $quality->label()], GenerationQuality::cases()),
        ]);
    }

    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $filter = $request->string('status')->value();
        $statuses = self::FILTERS[$filter]['statuses'] ?? null;

        $generations = $user->generations()
            ->when($statuses !== null, fn (Builder $query): Builder => $query->whereIn('status', $statuses ?? []))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'status' => $statuses === null ? null : $filter,
            'statuses' => array_map(
                fn (string $value, array $filter): array => ['value' => $value, 'label' => $filter['label']],
                array_keys(self::FILTERS),
                self::FILTERS,
            ),
        ]);
    }

    public function store(CreateGenerationRequest $request, #[CurrentUser] User $user, CreateGeneration $action): RedirectResponse
    {
        $action->handle($user, $request->prompt(), $request->size(), $request->quality(), $request->referenceImages());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation queued.')]);

        return back();
    }
}
