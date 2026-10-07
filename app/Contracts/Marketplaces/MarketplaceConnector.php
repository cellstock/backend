<?php

namespace App\Contracts\Marketplaces;

use App\Models\MarketplaceConnection;

interface MarketplaceConnector
{
    public function credentialRules(): array;

    public function testConnection(
        array $credentials
    ): array;

    /**
     * @return array{
     *     processed_count: int,
     *     success_count: int,
     *     failed_count: int
     * }
     */
    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array;
}