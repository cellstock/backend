<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceConnectionOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credentials' => ['sometimes', 'array'],
            'credentials.api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'credentials.environment' => ['sometimes', 'nullable', 'string', 'in:sandbox,production'],
        ];
    }
}
