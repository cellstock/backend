<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMarketplaceConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'credentials' => [
                'sometimes',
                'array',
            ],
            'credentials.api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'credentials.access_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'credentials.platform' => ['sometimes', 'nullable', 'string', 'in:eu,na,ap'],
            'credentials.country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}-[a-z]{2}$/'],
            'credentials.environment' => ['sometimes', 'nullable', 'string', 'in:preproduction,production'],
            'settings' => [
                'sometimes',
                'array',
            ],
            'settings.sync_orders' => [
                'sometimes',
                'boolean',
            ],
            'settings.sync_products' => [
                'sometimes',
                'boolean',
            ],
            'settings.sync_inventory' => [
                'sometimes',
                'boolean',
            ],
            'settings.markets' => ['sometimes', 'array'],
            'settings.markets.*.code' => ['required', 'string', 'max:10'],
            'settings.markets.*.name' => ['required', 'string', 'max:100'],
            'settings.markets.*.currency_code' => ['nullable', 'string', 'size:3'],
            'settings.markets.*.manage_orders' => ['required', 'boolean'],
            'settings.markets.*.manage_offers' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    ! $this->has('account_name')
                    && ! $this->has('credentials')
                    && ! $this->has('settings')
                ) {
                    $validator->errors()->add(
                        'request',
                        'At least one field must be provided.'
                    );
                }
            },
        ];
    }
}
