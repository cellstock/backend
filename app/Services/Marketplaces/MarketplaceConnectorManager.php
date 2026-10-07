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

        $connector = match (
            $marketplace->slug.':'.$driver
        ) {
            'back-market:mock' =>
                MockMarketplaceConnector::class,

            'back-market:real' =>
                BackMarketConnector::class,

            'refurbed:mock' =>
                MockRefurbedConnector::class,

            'refurbed:real' =>
                RefurbedConnector::class,

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
        return match ($marketplace->slug) {
            'back-market' => config(
                'services.back_market.driver',
                'mock'
            ),

            'refurbed' => config(
                'services.refurbed.driver',
                'mock'
            ),

            default => config(
                'marketplaces.driver',
                'mock'
            ),
        };
    }
}