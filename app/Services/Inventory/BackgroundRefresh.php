<?php
namespace App\Services\Inventory;

use App\Models\MarketplaceConnection;
use App\Jobs\SyncRefurbedInventory;
use App\Jobs\SyncRefurbedReferenceData;
use Illuminate\Support\Facades\Cache;

class BackgroundRefresh
{
    public function request(MarketplaceConnection $connection, bool $inventory = false): void
    {
        $key = 'refurbed-refresh:'.($inventory ? 'inventory:' : 'reference:').$connection->id;
        if (! Cache::add($key, true, 120)) return;
        try {
            if ($inventory) SyncRefurbedInventory::dispatch($connection->id);
            else SyncRefurbedReferenceData::dispatch($connection->id);
        } catch (\Throwable $exception) {
            Cache::forget($key);
            report($exception);
        }
    }
}
