<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AppSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseCreditsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', Rule::in(resolve(AppSettings::class)->creditPacks())],
        ];
    }
}
