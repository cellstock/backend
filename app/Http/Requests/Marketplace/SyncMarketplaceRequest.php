<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\MarketplaceSyncType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncMarketplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => [
                'required',
                Rule::enum(MarketplaceSyncType::class),
            ],
        ];
    }
}