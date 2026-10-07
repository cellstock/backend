<?php

namespace App\Services\Marketplaces\Mappers;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class RefurbedOrderMapper
{
    public function mapOrder(array $source): array
    {
        $externalOrderId = $this->requiredId(
            $source['id'] ?? null,
            'order'
        );

        $totalAmount = $this->money(
            $source['total_charged'] ?? 0
        );

        $shippingAmount = $this->calculateShippingAmount(
            $source['items'] ?? []
        );

        $taxAmount = $this->money(
            $source['total_vat_charged'] ?? 0
        );

        $subtotal = $this->subtractMoney(
            $totalAmount,
            $shippingAmount,
            $taxAmount
        );

        return [
            'external_order_id' => $externalOrderId,
            'status' => $source['state'] ?? null,
            'country_code' => data_get(
                $source,
                'shipping_address.country_code'
            ),
            'currency' => $source['currency_code'] ?? null,

            'subtotal' => $subtotal,
            'shipping_amount' => $shippingAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,

            'payment_method' => $source['payment_method'] ?? null,

            'tracking_number' => null,
            'tracking_url' => $this->firstTrackingUrl(
                $source['items'] ?? []
            ),
            'shipper' => null,

            'shipping_address' =>
                $source['shipping_address'] ?? null,

            'billing_address' =>
                $source['invoice_address'] ?? null,

            'raw_payload' => $source,

            'ordered_at' => $this->date(
                $source['released_at'] ?? null
            ),
            'modified_at' => null,
            'paid_at' => null,
            'shipped_at' => null,
            'last_synced_at' => now(),
        ];
    }

    public function mapItems(array $source): array
    {
        return array_map(
            fn (array $item): array => $this->mapItem($item),
            $source['items'] ?? []
        );
    }

    private function mapItem(array $item): array
    {
        $externalItemId = $this->requiredId(
            $item['id'] ?? null,
            'order item'
        );

        $currency = $item['currency_code'] ?? null;
        $settlementCurrency =
            $item['settlement_currency_code'] ?? null;

        $commissionAmount = $currency === $settlementCurrency
            ? $this->money(
                $item['settlement_total_commission'] ?? 0
            )
            : '0.00';

        return [
            'external_order_item_id' => $externalItemId,

            'external_listing_id' => $this->nullableString(
                data_get($item, 'offer_data.offer_id')
            ),

            'external_product_id' => null,

            'sku' => $item['sku'] ?? null,
            'title' => $item['name'] ?? null,
            'brand' => null,

            'quantity' => 1,

            'unit_price' => $this->money(
                $item['total_charged'] ?? 0
            ),

            'shipping_amount' => $this->money(
                data_get($item, 'offer_data.shipping_costs', 0)
            ),

            'commission_amount' => $commissionAmount,

            'tax_amount' => $this->money(
                $item['vat_charged'] ?? 0
            ),

            'currency' => $currency,
            'status' => $item['state'] ?? null,

            'condition' => data_get(
                $item,
                'offer_data.offer_grading'
            ),

            'return_reason' => $item['refund_eligibility'] ?? null,
            'return_message' => null,

            'raw_payload' => $item,
        ];
    }

    private function calculateShippingAmount(array $items): string
    {
        $totalCents = 0;

        foreach ($items as $item) {
            $totalCents += $this->toCents(
                data_get($item, 'offer_data.shipping_costs', 0)
            );
        }

        return $this->fromCents($totalCents);
    }

    private function firstTrackingUrl(array $items): ?string
    {
        foreach ($items as $item) {
            $trackingUrl = trim(
                (string) ($item['parcel_tracking_url'] ?? '')
            );

            if ($trackingUrl !== '') {
                return $trackingUrl;
            }
        }

        return null;
    }

    private function subtractMoney(
        string $total,
        string ...$amounts
    ): string {
        $remainingCents = $this->toCents($total);

        foreach ($amounts as $amount) {
            $remainingCents -= $this->toCents($amount);
        }

        return $this->fromCents(
            max(0, $remainingCents)
        );
    }

    private function money(mixed $value): string
    {
        return $this->fromCents(
            $this->toCents($value)
        );
    }

    private function toCents(mixed $value): int
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return 0;
        }

        $negative = str_starts_with($normalized, '-');

        $normalized = ltrim($normalized, '-');
        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            '0'
        );

        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2);

        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        $formatted = sprintf(
            '%d.%02d',
            intdiv($cents, 100),
            $cents % 100
        );

        return $negative ? "-{$formatted}" : $formatted;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function requiredId(mixed $value, string $resource): string
    {
        if ($value === null || (string) $value === '') {
            throw new InvalidArgumentException(
                "Refurbed {$resource} is missing its id."
            );
        }

        return (string) $value;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value
            ? CarbonImmutable::parse($value)
            : null;
    }
}
