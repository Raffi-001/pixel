<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RetryGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class GenerationRetryController
{
    public function store(#[CurrentUser] User $user, Generation $generation, RetryGeneration $action): RedirectResponse
    {
        abort_unless($generation->user_id === $user->id, 404);

        $action->handle($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retry queued.')]);

        return back();
    }
}
