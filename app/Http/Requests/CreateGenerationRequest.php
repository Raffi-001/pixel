<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateGenerationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => [$this->isEstimate() ? 'nullable' : 'required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            'submission_key' => [$this->isEstimate() ? 'nullable' : 'required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->value();
    }

    public function size(): GenerationSize
    {
        return GenerationSize::from($this->string('size')->value());
    }

    public function quality(): GenerationQuality
    {
        return GenerationQuality::from($this->string('quality')->value());
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> $urls */
        $urls = array_values($this->array('reference_images'));

        return $urls;
    }

    public function submissionKey(): ?string
    {
        return $this->filled('submission_key') ? $this->string('submission_key')->value() : null;
    }

    private function isEstimate(): bool
    {
        return $this->routeIs('generations.estimate');
    }
}
