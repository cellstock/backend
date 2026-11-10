<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isShipped = fn (): bool => $this->string('status')->upper()->value() === 'SHIPPED';

        return [
            'status' => [
                'required',
                'string',
                Rule::in(['REJECTED', 'CANCELLED', 'ACCEPTED', 'SHIPPED', 'RETURNED']),
            ],
            'tracking_url' => [
                Rule::requiredIf(fn (): bool => $isShipped() && ! $this->filled('tracking_number')),
                'nullable',
                'string',
                'max:2000',
            ],
            'tracking_number' => [
                Rule::requiredIf(fn (): bool => $isShipped() && ! $this->filled('tracking_url')),
                'nullable',
                'string',
                'max:255',
            ],
            'item_identifiers' => ['nullable', 'array'],
            'item_identifiers.*.id' => ['required', 'string'],
            'item_identifiers.*.identifier_type' => ['required', Rule::in(['IMEI', 'SERIAL_NUMBER'])],
            'item_identifiers.*.value' => ['required', 'string', 'max:255'],
        ];
    }
}
