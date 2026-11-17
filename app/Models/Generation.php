<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string|null $retry_of_id
 * @property-read string|null $submission_key
 * @property-read string|null $provider_id
 * @property-read GenerationStatus $status
 * @property-read string $prompt
 * @property-read GenerationSize $size
 * @property-read GenerationQuality $quality
 * @property-read list<string> $reference_images
 * @property-read int $markup_bps
 * @property-read array<string, string> $estimate_rates
 * @property-read int $estimated_min_nano
 * @property-read int $estimated_max_nano
 * @property-read int $held_nano
 * @property-read array<string, string>|null $rates
 * @property-read int|null $input_text_tokens
 * @property-read int|null $input_image_tokens
 * @property-read int|null $output_tokens
 * @property-read int|null $reasoning_tokens
 * @property-read int|null $provider_cost_nano
 * @property-read int|null $charged_nano
 * @property-read string|null $image_url
 * @property-read CarbonInterface|null $image_url_expires_at
 * @property-read string|null $error_code
 * @property-read string|null $error_message
 * @property-read CarbonInterface|null $submitted_at
 * @property-read CarbonInterface|null $started_at
 * @property-read CarbonInterface|null $completed_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'retry_of_id' => 'string',
            'submission_key' => 'string',
            'provider_id' => 'string',
            'status' => GenerationStatus::class,
            'prompt' => 'string',
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'reference_images' => 'array',
            'markup_bps' => 'integer',
            'estimate_rates' => 'array',
            'estimated_min_nano' => 'integer',
            'estimated_max_nano' => 'integer',
            'held_nano' => 'integer',
            'rates' => 'array',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nano' => 'integer',
            'charged_nano' => 'integer',
            'image_url' => 'string',
            'image_url_expires_at' => 'datetime',
            'error_code' => 'string',
            'error_message' => 'string',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }
}
