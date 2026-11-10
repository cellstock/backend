<?php

namespace App\Services\Marketplaces\Mappers;

use RuntimeException;

class LightspeedOrderMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapOrder(array $data): array
    {
        if (! isset($data['id'])) {
            throw new RuntimeException('Lightspeed order ID is missing.');
        }

        $country = strtoupper((string) (
            data_get($data, 'addressShippingCountry.code')
            ?? data_get($data, 'addressBillingCountry.code')
            ?? 'NL'
        ));

        $subtotal = (float) ($data['priceExcl'] ?? 0);
        $total = (float) ($data['priceIncl'] ?? $subtotal);
        $shipping = (float) ($data['shipmentPriceIncl'] ?? $data['shipmentPriceExcl'] ?? 0);
        $tax = max(0, $total - $subtotal);

        return [
            'external_order_id' => (string) $data['id'],
            'status' => $this->mapOrderStatus(
                (string) ($data['status'] ?? ''),
                (string) ($data['shipmentStatus'] ?? ''),
                (string) ($data['paymentStatus'] ?? '')
            ),
            'country_code' => $country,
            'currency' => 'EUR',
            'subtotal' => $subtotal,
            'shipping_amount' => $shipping,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'payment_method' => $data['paymentTitle'] ?? data_get($data, 'paymentData.method'),
            'payment_status' => $data['paymentStatus'] ?? null,
            'vat_number' => ($data['companyVatNumber'] ?? '') !== '' ? (string) $data['companyVatNumber'] : null,
            'tracking_number' => null,
            'tracking_url' => null,
            'shipper' => $data['shipmentTitle'] ?? null,
            'shipping_address' => $this->mapAddress($data, 'Shipping'),
            'billing_address' => $this->mapAddress($data, 'Billing'),
            'ordered_at' => $data['createdAt'] ?? null,
            'modified_at' => $data['updatedAt'] ?? null,
            'paid_at' => ($data['paymentStatus'] ?? '') === 'paid' ? ($data['updatedAt'] ?? $data['createdAt'] ?? null) : null,
            'shipped_at' => $data['shippingDate'] ?? null,
            'last_synced_at' => now(),
            'raw_payload' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapItem(array $data, string $orderStatus): array
    {
        if (! isset($data['id'])) {
            throw new RuntimeException('Lightspeed order product ID is missing.');
        }

        $quantity = max(1, (int) ($data['quantityOrdered'] ?? 1));
        $lineTotal = (float) ($data['priceIncl'] ?? $data['priceExcl'] ?? 0);
        $unitPrice = (float) ($data['basePriceIncl'] ?? $data['basePriceExcl'] ?? 0);
        if ($unitPrice <= 0 && $quantity > 0) {
            $unitPrice = $lineTotal / $quantity;
        }

        $title = trim(implode(' ', array_filter([
            (string) ($data['productTitle'] ?? ''),
            (string) ($data['variantTitle'] ?? ''),
        ])));

        return [
            'external_order_item_id' => (string) $data['id'],
            'external_listing_id' => isset($data['variant']['resource']['id'])
                ? (string) $data['variant']['resource']['id']
                : null,
            'external_product_id' => isset($data['product']['resource']['id'])
                ? (string) $data['product']['resource']['id']
                : null,
            'sku' => ($data['sku'] ?? '') !== ''
                ? (string) $data['sku']
                : (($data['articleCode'] ?? '') !== '' ? (string) $data['articleCode'] : null),
            'title' => $title !== '' ? $title : 'Lightspeed product',
            'brand' => ($data['brandTitle'] ?? '') !== '' ? (string) $data['brandTitle'] : null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'shipping_amount' => 0,
            'commission_amount' => 0,
            'tax_amount' => max(0, (float) ($data['priceIncl'] ?? 0) - (float) ($data['priceExcl'] ?? 0)),
            'currency' => 'EUR',
            'status' => $orderStatus,
            'condition' => null,
            'return_reason' => null,
            'return_message' => null,
            'raw_payload' => $data,
        ];
    }

    public function mapOrderStatus(string $status, string $shipmentStatus = '', string $paymentStatus = ''): string
    {
        $status = strtolower(trim($status));
        $shipmentStatus = strtolower(trim($shipmentStatus));

        if ($status === 'cancelled' || $shipmentStatus === 'cancelled') {
            return 'CANCELLED';
        }

        if (
            in_array($status, ['completed_shipped', 'completed', 'completed_picked_up'], true)
            || $shipmentStatus === 'shipped'
        ) {
            return 'SHIPPED';
        }

        if (in_array($status, ['processing_awaiting_payment', 'on_hold'], true)
            || $paymentStatus === 'not_paid'
        ) {
            return 'NEW';
        }

        if (str_starts_with($status, 'processing') || $status === 'new') {
            return 'ACCEPTED';
        }

        return 'NEW';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAddress(array $data, string $type): array
    {
        $prefix = 'address'.$type;

        return [
            'name' => $data[$prefix.'Name'] ?? trim(($data['firstname'] ?? '').' '.($data['lastname'] ?? '')),
            'company' => $data[$prefix.'Company'] ?: ($data['companyName'] ?? null),
            'street' => trim(implode(' ', array_filter([
                (string) ($data[$prefix.'Street'] ?? ''),
                (string) ($data[$prefix.'Number'] ?? ''),
                (string) ($data[$prefix.'Extension'] ?? ''),
            ]))),
            'street2' => $data[$prefix.'Street2'] ?? null,
            'zipcode' => $data[$prefix.'Zipcode'] ?? null,
            'city' => $data[$prefix.'City'] ?? null,
            'region' => $data[$prefix.'Region'] ?? data_get($data, $prefix.'RegionData.name'),
            'country_code' => strtoupper((string) data_get($data, $prefix.'Country.code', 'NL')),
            'country' => data_get($data, $prefix.'Country.title'),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? $data['mobile'] ?? null,
        ];
    }
}
