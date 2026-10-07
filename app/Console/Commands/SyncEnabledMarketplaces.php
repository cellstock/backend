<?php

namespace App\Console\Commands;

use App\Enums\MarketplaceConnectionStatus;
use App\Enums\MarketplaceSyncType;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\MarketplaceService;
use Illuminate\Console\Command;

class SyncEnabledMarketplaces extends Command
{
    protected $signature = 'marketplaces:sync-enabled';

    protected $description = 'Queue enabled marketplace synchronization jobs';

    public function handle(MarketplaceService $marketplaceService): int
    {
        MarketplaceConnection::query()
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->with(['user', 'marketplace'])
            ->each(function (MarketplaceConnection $connection) use ($marketplaceService): void {
                foreach ($this->enabledTypes($connection) as $type) {
                    try {
                        $marketplaceService->sync(
                            user: $connection->user,
                            marketplace: $connection->marketplace,
                            type: $type,
                        );
                    } catch (BusinessRuleException $exception) {
                        if ($exception->status() !== 409) {
                            report($exception);
                        }
                    }
                }
            });

        return self::SUCCESS;
    }

    /** @return list<MarketplaceSyncType> */
    private function enabledTypes(MarketplaceConnection $connection): array
    {
        $settings = $connection->settings ?? [];

        if ($connection->marketplace->slug === 'refurbed') {
            return ($settings['sync_orders'] ?? false)
                ? [MarketplaceSyncType::Orders]
                : [];
        }

        $capabilities = $connection->marketplace->capabilities ?? [];
        $types = [];

        if (($settings['sync_orders'] ?? false) && in_array('orders', $capabilities, true)) {
            $types[] = MarketplaceSyncType::Orders;
        }

        if (($settings['sync_products'] ?? false) && in_array('products', $capabilities, true)) {
            $types[] = MarketplaceSyncType::Products;
        }

        if (($settings['sync_inventory'] ?? false) && in_array('inventory', $capabilities, true)) {
            $types[] = MarketplaceSyncType::Inventory;
        }

        return $types;
    }
}
