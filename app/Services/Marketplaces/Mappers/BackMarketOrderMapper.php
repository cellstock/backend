<?php

namespace App\Services\Marketplaces\Mappers;

use RuntimeException;

class BackMarketOrderMapper
{
    public function mapOrder(array $data): array
    {
        if (! isset($data['order_id'])) {
            throw new RuntimeException(
                'Back Market order ID is missing.'
            );
        }

        $subtotal = (float) ($data['price'] ?? 0);
        $shipping = (float) ($data['shipping_price'] ?? 0);

        return [
            'external_order_id' => (string) $data['order_id'],
            'status' => $this->mapOrderStatus(
                $data['state'] ?? null
            ),
            'country_code' => $data['country_code'] ?? null,
            'currency' => $data['currency'] ?? null,

            'subtotal' => $subtotal,
            'shipping_amount' => $shipping,
            'tax_amount' => (float) ($data['sales_taxes'] ?? 0),
            'total_amount' => $subtotal + $shipping,

            'payment_method' => $data['payment_method'] ?? null,

            'tracking_number' =>
                $data['tracking_number'] ?? null,

            'tracking_url' =>
                $data['tracking_url'] ?? null,

            'shipper' =>
                $data['shipper_display'] ?? null,

            'shipping_address' =>
                $data['shipping_address'] ?? null,

            'billing_address' =>
                $data['billing_address'] ?? null,

            'ordered_at' =>
                $data['date_creation'] ?? null,

            'modified_at' =>
                $data['date_modification'] ?? null,

            'paid_at' =>
                $data['date_payment'] ?? null,

            'shipped_at' =>
                $data['date_shipping'] ?? null,

            'last_synced_at' => now(),
            'raw_payload' => $data,
        ];
    }

    public function mapItem(array $data): array
    {
        if (! isset($data['id'])) {
            throw new RuntimeException(
                'Back Market order item ID is missing.'
            );
        }

        return [
            'external_order_item_id' => (string) $data['id'],

            'external_listing_id' => isset($data['listing_id'])
                ? (string) $data['listing_id']
                : null,

            'external_product_id' => isset($data['product_id'])
                ? (string) $data['product_id']
                : null,

            'sku' => $data['listing'] ?? null,
            'title' => $data['product'] ?? null,
            'brand' => $data['brand'] ?? null,

            'quantity' => (int) ($data['quantity'] ?? 1),

            'unit_price' =>
                (float) ($data['price'] ?? 0),

            'shipping_amount' =>
                (float) ($data['shipping_price'] ?? 0),

            'commission_amount' =>
                (float) ($data['orderline_fee'] ?? 0),

            'tax_amount' =>
                (float) ($data['sales_taxes'] ?? 0),

            'currency' => $data['currency'] ?? null,

            'status' => $this->mapOrderItemStatus(
                $data['state'] ?? null
            ),

            'condition' => $this->mapCondition(
                $data['condition'] ?? null
            ),

            'return_reason' => isset($data['return_reason'])
                ? (string) $data['return_reason']
                : null,

            'return_message' =>
                $data['return_message'] ?? null,

            'raw_payload' => $data,
        ];
    }

    private function mapOrderStatus(
        int|string|null $status
    ): string {
        return match ((int) $status) {
            0 => 'new',
            1 => 'paid',
            3 => 'shipping_pending',
            8 => 'unpaid',
            9 => 'shipped',
            10 => 'pending_payment',
            default => 'unknown',
        };
    }

    private function mapOrderItemStatus(
        int|string|null $status
    ): string {
        return match ((int) $status) {
            0 => 'new',
            1 => 'paid',
            2 => 'accepted',
            3 => 'shipped',
            4 => 'cancelled',
            5 => 'refunded_before_shipping',
            6 => 'refunded_after_shipping',
            7 => 'unpaid',
            8 => 'pending_payment',
            9 => 'on_hold',
            default => 'unknown',
        };
    }

    private function mapCondition(
        int|string|null $condition
    ): ?string {
        if ($condition === null) {
            return null;
        }

        return match ((int) $condition) {
            9 => 'premium',
            10 => 'excellent',
            11 => 'good',
            12 => 'fair',
            default => 'unknown',
        };
    }
}