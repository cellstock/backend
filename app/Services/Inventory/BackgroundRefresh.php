<?php
namespace App\Services\Inventory;

use App\Models\MarketplaceConnection;
use App\Jobs\SyncRefurbedInventory;
use App\Jobs\SyncRefurbedReferenceData;
use App\Support\MarketplaceSlug;
use Illuminate\Support\Facades\Cache;

class BackgroundRefresh
{
    public function request(MarketplaceConnection $connection, bool $inventory = false): void
    {
        // Refurbed-only background refresh. Lightspeed / Back Market sync via SyncMarketplace.
        if (! MarketplaceSlug::isRefurbed($connection->marketplace?->slug)) {
            return;
        }

        $key = 'refurbed-refresh:'.($inventory ? 'inventory:' : 'reference:').$connection->id;
        if (! Cache::add($key, true, 120)) {
            return;
        }
        try {
            if ($inventory) {
                SyncRefurbedInventory::dispatch($connection->id);
            } else {
                SyncRefurbedReferenceData::dispatch($connection->id);
            }
        } catch (\Throwable $exception) {
            Cache::forget($key);
            report($exception);
        }
    }
}
