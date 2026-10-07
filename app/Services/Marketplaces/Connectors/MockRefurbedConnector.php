<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use RuntimeException;

class MockRefurbedConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace
    ) {
    }

    public function credentialRules(): array
    {
        return [
            'api_key' => [
                'required',
                'string',
                'min:8',
                'max:2000',
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
                'The mock Refurbed API rejected the credentials.'
            );
        }

        return [
            'external_account_id' =>
                'mock-refurbed-account',

            'account_name' =>
                'CelleXa Refurbed Account',
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

        throw new RuntimeException(
            "Mock Refurbed {$type} synchronization is not implemented yet."
        );
    }
}