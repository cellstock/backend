<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Services\Marketplaces\Sync\LightspeedListingSynchronizer;
use App\Services\Marketplaces\Sync\LightspeedOrderSynchronizer;

class MockLightspeedConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly LightspeedListingSynchronizer $listingSynchronizer,
        private readonly LightspeedOrderSynchronizer $orderSynchronizer,
    ) {}

    public function credentialRules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'cluster' => ['sometimes', 'nullable', 'string', 'in:eu1,us1'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    public function testConnection(array $credentials): array
    {
        return [
            'external_account_id' => 'lightspeed-mock-telefoongigant',
            'account_name' => 'Telefoongigant (mock)',
        ];
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        $listings = $this->mockListings();
        $orders = $this->mockOrders();

        return match ($type) {
            'products', 'inventory' => $this->listingSynchronizer->sync($connection, $listings),
            'orders' => $this->orderSynchronizer->sync($connection, $orders),
            'full' => $this->merge(
                $this->orderSynchronizer->sync($connection, $orders),
                $this->listingSynchronizer->sync($connection, $listings),
            ),
            default => ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0],
        };
    }

    public function updateOrderStatus(
        MarketplaceOrder $order,
        string $status,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
    ): MarketplaceOrder {
        $mapped = strtoupper($status);
        $local = match ($mapped) {
            'ACCEPTED', 'ACCEPT', 'CONFIRMED' => 'ACCEPTED',
            'SHIPPED', 'SHIPPING', 'SENT' => 'SHIPPED',
            'CANCELLED', 'CANCELED', 'REJECTED' => 'CANCELLED',
            default => $mapped,
        };

        $order->forceFill([
            'status' => $local,
            'tracking_number' => $trackingNumber ?: $order->tracking_number,
            'tracking_url' => $trackingUrl ?: $order->tracking_url,
            'shipped_at' => $local === 'SHIPPED' ? now() : $order->shipped_at,
            'last_synced_at' => now(),
        ])->save();

        $order->items()->update(['status' => $local]);

        return $order->fresh(['connection.marketplace', 'items']) ?? $order;
    }

    /**
     * @param  list<array{variant_id: string, stock: int}>  $rows
     */
    public function updateVariantStocks(MarketplaceConnection $connection, array $rows): int
    {
        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mockListings(): array
    {
        return [
            [
                'product' => [
                    'id' => 1001,
                    'title' => 'Apple iPad Air 5',
                    'fulltitle' => 'Apple iPad Air 5 (2022) 64GB WiFi',
                    'url' => 'apple-ipad-air-5',
                    'isVisible' => true,
                    'createdAt' => now()->subDays(30)->toIso8601String(),
                ],
                'variant' => [
                    'id' => 2001,
                    'title' => 'Space Gray',
                    'sku' => 'IPAD-AIR5-64-SG',
                    'ean' => '0194252713583',
                    'articleCode' => 'IPAD-AIR5-64-SG',
                    'priceIncl' => 499.00,
                    'priceExcl' => 412.40,
                    'stockLevel' => 3,
                    'createdAt' => now()->subDays(30)->toIso8601String(),
                ],
            ],
            [
                'product' => [
                    'id' => 1002,
                    'title' => 'Apple iPad Pro 11',
                    'fulltitle' => 'Apple iPad Pro 11 (2022) 128GB WiFi',
                    'url' => 'apple-ipad-pro-11',
                    'isVisible' => true,
                    'createdAt' => now()->subDays(10)->toIso8601String(),
                ],
                'variant' => [
                    'id' => 2002,
                    'title' => 'Silver',
                    'sku' => 'IPAD-PRO11-128-SV',
                    'ean' => '0194253401122',
                    'articleCode' => 'IPAD-PRO11-128-SV',
                    'priceIncl' => 799.00,
                    'priceExcl' => 660.33,
                    'stockLevel' => 1,
                    'createdAt' => now()->subDays(10)->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @return list<array{order: array<string, mixed>, products: list<array<string, mixed>>}>
     */
    private function mockOrders(): array
    {
        return [
            [
                'order' => [
                    'id' => 9001,
                    'number' => 'TG-IPAD-001',
                    'status' => 'processing_awaiting_shipment',
                    'paymentStatus' => 'paid',
                    'shipmentStatus' => 'not_shipped',
                    'priceExcl' => 412.40,
                    'priceIncl' => 499.00,
                    'shipmentPriceIncl' => 0,
                    'shipmentTitle' => 'Standard',
                    'paymentTitle' => 'iDEAL',
                    'firstname' => 'Jan',
                    'lastname' => 'Jansen',
                    'email' => 'jan@example.nl',
                    'phone' => '+31600000000',
                    'addressShippingName' => 'Jan Jansen',
                    'addressShippingStreet' => 'Hoofdstraat',
                    'addressShippingNumber' => '1',
                    'addressShippingZipcode' => '1011AA',
                    'addressShippingCity' => 'Amsterdam',
                    'addressShippingCountry' => ['code' => 'nl', 'title' => 'Netherlands'],
                    'addressBillingName' => 'Jan Jansen',
                    'addressBillingStreet' => 'Hoofdstraat',
                    'addressBillingNumber' => '1',
                    'addressBillingZipcode' => '1011AA',
                    'addressBillingCity' => 'Amsterdam',
                    'addressBillingCountry' => ['code' => 'nl', 'title' => 'Netherlands'],
                    'createdAt' => now()->subDay()->toIso8601String(),
                    'updatedAt' => now()->subHour()->toIso8601String(),
                ],
                'products' => [
                    [
                        'id' => 9101,
                        'productTitle' => 'Apple iPad Air 5 (2022) 64GB WiFi',
                        'variantTitle' => 'Space Gray',
                        'brandTitle' => 'Apple',
                        'sku' => 'IPAD-AIR5-64-SG',
                        'articleCode' => 'IPAD-AIR5-64-SG',
                        'ean' => '0194252713583',
                        'quantityOrdered' => 1,
                        'basePriceIncl' => 499.00,
                        'basePriceExcl' => 412.40,
                        'priceIncl' => 499.00,
                        'priceExcl' => 412.40,
                        'product' => ['resource' => ['id' => 1001]],
                        'variant' => ['resource' => ['id' => 2001]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{processed_count: int, success_count: int, failed_count: int}  $a
     * @param  array{processed_count: int, success_count: int, failed_count: int}  $b
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    private function merge(array $a, array $b): array
    {
        return [
            'processed_count' => $a['processed_count'] + $b['processed_count'],
            'success_count' => $a['success_count'] + $b['success_count'],
            'failed_count' => $a['failed_count'] + $b['failed_count'],
        ];
    }
}
