<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'marketplace' => [
                'nullable',
                'string',
                Rule::exists('marketplaces', 'slug')
                    ->where('is_active', true),
            ],

            'status' => [
                'nullable',
                'string',
                'max:100',
            ],

            'country_code' => [
                'nullable',
                'string',
                'size:2',
            ],

            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],

            'sort_by' => [
                'nullable',
                Rule::in([
                    'external_order_id',
                    'status',
                    'country_code',
                    'total_amount',
                    'ordered_at',
                    'created_at',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                Rule::in(['asc', 'desc']),
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:5',
                'max:100',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->filled('country_code')
                ? strtoupper(
                    (string) $this->input('country_code')
                )
                : null,

            'marketplace' => $this->filled('marketplace')
                ? strtolower(
                    (string) $this->input('marketplace')
                )
                : null,
        ]);
    }
}