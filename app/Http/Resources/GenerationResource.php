<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Generation;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->id,
            'retry_of_id' => $this->retry_of_id,
            'provider_id' => $this->provider_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_min' => Money::format($this->estimated_min_nano),
            'estimated_max' => Money::format($this->estimated_max_nano),
            'charged' => $this->charged_nano === null ? null : Money::format($this->charged_nano),
            'charged_exact' => $this->charged_nano === null ? null : Money::toDecimal($this->charged_nano),
            'markup_percent' => Money::formatBps($this->markup_bps),
            'usage' => $this->input_text_tokens === null ? null : [
                'input_text_tokens' => $this->input_text_tokens,
                'input_image_tokens' => $this->input_image_tokens,
                'output_tokens' => $this->output_tokens,
                'reasoning_tokens' => $this->reasoning_tokens,
            ],
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $this->image_url !== null && $imageExpired,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
