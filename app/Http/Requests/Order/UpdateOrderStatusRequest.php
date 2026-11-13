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
        return [
            'status' => [
                'required',
                'string',
                Rule::in(['REJECTED', 'CANCELLED', 'ACCEPTED', 'SHIPPED', 'RETURNED']),
            ],
            'tracking_url' => [
                Rule::requiredIf(fn (): bool => $this->string('status')->upper()->value() === 'SHIPPED'),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'item_identifiers' => ['nullable', 'array'],
            'item_identifiers.*.id' => ['required', 'string'],
            'item_identifiers.*.identifier_type' => ['required', Rule::in(['IMEI', 'SERIAL_NUMBER'])],
            'item_identifiers.*.value' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $status = strtoupper((string) $this->input('status'));
            $identifiers = $this->input('item_identifiers');

            if (! is_array($identifiers) || $identifiers === []) {
                return;
            }

            if (! in_array($status, ['SHIPPED', 'RETURNED'], true)) {
                $validator->errors()->add(
                    'item_identifiers',
                    'Serial/IMEI can only be sent when marking the order as SHIPPED or RETURNED.',
                );

                return;
            }

        });
    }
}
