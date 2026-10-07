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
            'credentials.api_key' => ['required_with:credentials', 'string', 'max:2000'],
        ];
    }
}
