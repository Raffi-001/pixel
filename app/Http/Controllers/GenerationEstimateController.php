<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EstimateGenerationCost;
use App\Http\Requests\CreateGenerationRequest;
use Illuminate\Http\JsonResponse;

final readonly class GenerationEstimateController
{
    public function store(CreateGenerationRequest $request, EstimateGenerationCost $action): JsonResponse
    {
        $estimate = $action->handle($request->prompt(), $request->size(), $request->quality(), $request->referenceImages());

        return response()->json($estimate->toArray());
    }
}
