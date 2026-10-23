<?php

namespace App\Services\Marketplaces;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Exceptions\BusinessRuleException;
use App\Models\Marketplace;
use App\Services\Marketplaces\Connectors\BackMarketConnector;
use App\Services\Marketplaces\Connectors\MockMarketplaceConnector;
use App\Services\Marketplaces\Connectors\MockRefurbedConnector;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Support\MarketplaceSlug;

class MarketplaceConnectorManager
{
    public function connector(
        Marketplace $marketplace
    ): MarketplaceConnector {
        $driver = $this->driverFor($marketplace);

        $connector = match (true) {
            MarketplaceSlug::isBackMarket($marketplace->slug)
                && $driver === 'mock' => MockMarketplaceConnector::class,

            MarketplaceSlug::isBackMarket($marketplace->slug)
                && $driver === 'real' => BackMarketConnector::class,

            MarketplaceSlug::isRefurbed($marketplace->slug)
                && $driver === 'mock' => MockRefurbedConnector::class,

            MarketplaceSlug::isRefurbed($marketplace->slug)
                && $driver === 'real' => RefurbedConnector::class,

            default => throw new BusinessRuleException(
                message: "No {$driver} connector is configured for {$marketplace->name}.",
                status: 501,
            ),
        };

        return app()->makeWith(
            $connector,
            [
                'marketplace' => $marketplace,
            ]
        );
    }

    private function driverFor(
        Marketplace $marketplace
    ): string {
        if (MarketplaceSlug::isBackMarket($marketplace->slug)) {
            return config('services.back_market.driver', 'mock');
        }

        if (MarketplaceSlug::isRefurbed($marketplace->slug)) {
            return config('services.refurbed.driver', 'mock');
        }

        return 'mock';
    }
}