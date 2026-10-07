<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Mocks\BackMarketFixtureLoader;
use App\Services\Marketplaces\Sync\BackMarketOrderSynchronizer;
use RuntimeException;

class MockMarketplaceConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly BackMarketFixtureLoader $fixtureLoader,
        private readonly BackMarketOrderSynchronizer $orderSynchronizer,
    ) {
    }

    public function credentialRules(): array
    {
        return [
            'api_key' => [
                'required',
                'string',
                'min:8',
                'max:500',
            ],
        ];
    }

    public function testConnection(array $credentials): array
    {
        if (
            ($credentials['api_key'] ?? null)
            === 'invalid-api-key'
        ) {
            throw new RuntimeException(
                'The mock marketplace rejected the credentials.'
            );
        }

        return [
            'external_account_id' =>
                'mock-'.$this->marketplace->slug.'-account',

            'account_name' =>
                'CelleXa '.$this->marketplace->name.' Account',
        ];
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        $this->testConnection(
            $connection->credentials
        );

        if ($this->marketplace->slug !== 'back-market') {
            throw new RuntimeException(
                "Mock synchronization is not implemented for {$this->marketplace->name}."
            );
        }

        return match ($type) {
            'orders' => $this->syncOrders($connection),

            'products',
            'inventory' => $this->syncListings(),

            'full' => $this->syncAll($connection),

            default => throw new RuntimeException(
                "Unsupported synchronization type: {$type}."
            ),
        };
    }

    private function syncOrders(
        MarketplaceConnection $connection
    ): array {
        $response = $this->fixtureLoader->orders();

        return $this->orderSynchronizer->sync(
            connection: $connection,
            orders: $response['results'] ?? [],
        );
    }

    private function syncListings(): array
    {
        $response = $this->fixtureLoader->listings();
        $count = count($response['results'] ?? []);

        return $this->successfulResult($count);
    }

    private function syncAll(
        MarketplaceConnection $connection
    ): array {
        $orderResult = $this->syncOrders($connection);
        $listingResult = $this->syncListings();

        return [
            'processed_count' =>
                $orderResult['processed_count']
                + $listingResult['processed_count'],

            'success_count' =>
                $orderResult['success_count']
                + $listingResult['success_count'],

            'failed_count' =>
                $orderResult['failed_count']
                + $listingResult['failed_count'],
        ];
    }

    private function successfulResult(int $count): array
    {
        return [
            'processed_count' => $count,
            'success_count' => $count,
            'failed_count' => 0,
        ];
    }
}