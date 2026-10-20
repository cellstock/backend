<?php

namespace App\Services\Marketplaces;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Exceptions\BusinessRuleException;
use App\Models\Marketplace;
use App\Services\Marketplaces\Connectors\BackMarketConnector;
use App\Services\Marketplaces\Connectors\MockMarketplaceConnector;
use App\Services\Marketplaces\Connectors\MockRefurbedConnector;
use App\Services\Marketplaces\Connectors\RefurbedConnector;

class MarketplaceConnectorManager
{
    public function connector(
        Marketplace $marketplace
    ): MarketplaceConnector {
        $driver = $this->driverFor($marketplace);

        $connector = match (true) {
            // Three Back Market platform slugs all use the same connector class
            in_array($marketplace->slug, ['back-market-eu', 'back-market-na', 'back-market-ap', 'back-market'])
                && $driver === 'mock' => MockMarketplaceConnector::class,

            in_array($marketplace->slug, ['back-market-eu', 'back-market-na', 'back-market-ap', 'back-market'])
                && $driver === 'real' => BackMarketConnector::class,

            $marketplace->slug === 'refurbed'
                && $driver === 'mock' => MockRefurbedConnector::class,

            $marketplace->slug === 'refurbed'
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
        return match (true) {
            in_array($marketplace->slug, ['back-market-eu', 'back-market-na', 'back-market-ap'])
                => config('services.back_market.driver', 'mock'),

            $marketplace->slug === 'refurbed'
                => config('services.refurbed.driver', 'mock'),

            // Legacy single back-market slug — treat same as the platform slugs
            $marketplace->slug === 'back-market'
                => config('services.back_market.driver', 'mock'),

            default => 'mock',
        };
    }
}