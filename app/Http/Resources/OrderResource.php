<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetail = $this->relationLoaded('items');
        $shippingAddress = $this->normalizeAddress(
            $this->shipping_address
        );
        $billingAddress = $this->normalizeAddress(
            $this->billing_address
        );
        $rawPayload = is_array($this->raw_payload)
            ? $this->raw_payload
            : [];

        return [
            'id' => $this->id,
            'external_order_id' => $this->external_order_id,
            'status' => $this->status,
            'workflow_status' => $this->workflow_status ?? $this->defaultWorkflowStatus(),
            'country_code' => $this->country_code,
            'currency' => $this->currency,

            'subtotal' => $this->subtotal,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,

            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status ?? $this->defaultPaymentStatus($rawPayload),
            'vat_number' => $this->vat_number,

            'payment' => [
                'is_paid' => $this->isPaid($rawPayload),
                'paid_amount' => $this->moneyValue(
                    $rawPayload['total_paid'] ?? null
                ),
                'refunded_amount' => $this->moneyValue(
                    $rawPayload['total_refunded'] ?? null
                ),
                'discount_amount' => $this->moneyValue(
                    $rawPayload['total_discount'] ?? null
                ),
            ],

            'invoice' => [
                'is_invoiceable' => (bool) ($rawPayload['is_invoiceable'] ?? false),
                'has_invoice' => (bool) ($rawPayload['has_invoice'] ?? false),
            ],

            'customer' => [
                'name' => $this->customerName(
                    $shippingAddress,
                    $billingAddress
                ),
                'email' => $this->nullableString(
                    $rawPayload['customer_email']
                        ?? data_get($this->shipping_address, 'email')
                        ?? data_get($this->billing_address, 'email')
                ),
            ],

            'tracking_number' => $this->tracking_number
                ?? $this->latestShippingLabelValue('tracking_number'),
            'tracking_url' => $this->tracking_url
                ?? $this->latestShippingLabelValue('tracking_url'),
            'shipper' => $this->shipper
                ?? $this->latestShippingLabelValue('carrier'),

            'ordered_at' => $this->ordered_at?->toISOString(),
            'modified_at' => $this->modified_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'last_synced_at' =>
                $this->last_synced_at?->toISOString(),
            'internal_comment' => $this->internal_comment,
            'contact_before' => $this->contact_before?->toDateString(),

            'marketplace' => $this->when(
                $this->relationLoaded('connection')
                    && $this->connection?->relationLoaded(
                        'marketplace'
                    ),
                fn (): array => [
                    'id' => $this->connection->marketplace->id,
                    'name' =>
                        $this->connection->marketplace->name,
                    'slug' =>
                        $this->connection->marketplace->slug,
                    'logo' =>
                        $this->connection->marketplace->logo,
                ]
            ),

            'shipping_address' => $this->when(
                $isDetail,
                fn () => $shippingAddress
            ),

            'billing_address' => $this->when(
                $isDetail,
                fn () => $billingAddress
            ),

            'items' => OrderItemResource::collection(
                $this->whenLoaded('items')
            ),

            'shipping_labels' => $this->whenLoaded(
                'shippingLabels',
                fn () => $this->shippingLabels->map(fn ($label) => [
                    'id' => $label->external_id,
                    'normal_printer_urls' => $label->normal_printer_urls ?? [],
                    'label_printer_url' => $label->label_printer_url,
                    'carrier' => $label->carrier,
                    'tracking_number' => $label->tracking_number,
                    'tracking_url' => $label->tracking_url,
                    'parcel_weight' => $label->parcel_weight,
                    'created_at' => $label->created_at?->toISOString(),
                ])
            ),
        ];
    }

    private function latestShippingLabelValue(string $field): mixed
    {
        if (! $this->relationLoaded('shippingLabels')) {
            return null;
        }

        return $this->shippingLabels->first()?->{$field};
    }

    private function normalizeAddress(mixed $address): ?array
    {
        if (! is_array($address) || $address === []) {
            return null;
        }

        $streetName = $this->nullableString(
            $address['street']
                ?? $address['street_name']
                ?? $address['address_line_1']
                ?? null
        );

        $houseNumber = $this->nullableString(
            $address['house_no']
                ?? $address['house_number']
                ?? null
        );

        return [
            'id' => $address['id'] ?? null,
            'countryCode' => $this->nullableString(
                $address['country_code']
                    ?? $address['countryCode']
                    ?? $address['country']
                    ?? null
            ),
            'country' => $this->nullableString(
                $address['country']
                    ?? $address['country_code']
                    ?? null
            ),
            'city' => $this->nullableString(
                $address['city']
                    ?? $address['town']
                    ?? null
            ),
            'postalCode' => $this->nullableString(
                $address['postalCode']
                    ?? $address['postal_code']
                    ?? $address['post_code']
                    ?? null
            ),
            'firstName' => $this->nullableString(
                $address['firstName']
                    ?? $address['first_name']
                    ?? null
            ),
            'lastName' => $this->nullableString(
                $address['lastName']
                    ?? $address['last_name']
                    ?? $address['family_name']
                    ?? null
            ),
            'company' => $this->nullableString(
                $address['company'] ?? null
            ),
            'phoneNumber' => $this->nullableString(
                $address['phoneNumber']
                    ?? $address['phone_number']
                    ?? $address['phone']
                    ?? null
            ),
            'email' => $this->nullableString($address['email'] ?? null),
            'state' => $this->nullableString(
                $address['state']
                    ?? $address['state_or_province']
                    ?? null
            ),
            'street' => trim(
                implode(' ', array_filter([
                    $streetName,
                    $houseNumber,
                ]))
            ),
            'streetName' => $streetName,
            'houseNumber' => $houseNumber,
            'street2' => $this->nullableString(
                $address['street2']
                    ?? $address['address_line_2']
                    ?? $address['supplement']
                    ?? null
            ),
        ];
    }

    private function customerName(
        ?array $shippingAddress,
        ?array $billingAddress
    ): ?string {
        $address = $shippingAddress ?? $billingAddress;

        if (! $address) {
            return null;
        }

        $name = trim(
            implode(' ', array_filter([
                $address['firstName'] ?? null,
                $address['lastName'] ?? null,
            ]))
        );

        return $name !== '' ? $name : null;
    }

    private function isPaid(array $rawPayload): bool
    {
        $paidAmount = $this->moneyValue(
            $rawPayload['total_paid'] ?? null
        );

        return ($paidAmount !== null && (float) $paidAmount > 0)
            || strtolower((string) $this->status) === 'paid';
    }

    private function defaultWorkflowStatus(): string
    {
        return match (strtoupper((string) $this->status)) {
            'SHIPPED' => 'dispatched',
            default => 'open',
        };
    }

    private function defaultPaymentStatus(array $rawPayload): string
    {
        $paid = (float) ($rawPayload['total_paid'] ?? 0);
        $refunded = (float) ($rawPayload['total_refunded'] ?? 0);

        return match (true) {
            $refunded > 0 && $paid > $refunded => 'partially_refunded',
            $refunded > 0 => 'refunded',
            $this->isPaid($rawPayload) => 'paid',
            default => 'waiting_for_payment',
        };
    }

    private function moneyValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
