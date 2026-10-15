<?php

namespace App\Console\Commands;

use App\Jobs\SyncRefurbedReferenceData as SyncJob;
use App\Models\MarketplaceConnection;
use Illuminate\Console\Command;

class SyncRefurbedReferenceData extends Command
{
    protected $signature = 'refurbed:sync-reference-data';
    protected $description = 'Queue automatic Refurbed returns and merchant-address synchronization.';

    public function handle(): int
    {
        MarketplaceConnection::query()
            ->where('status', 'connected')
            ->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))
            ->select('id')->lazyById(200)
            ->each(function (MarketplaceConnection $connection): void {
                app(\App\Services\Inventory\BackgroundRefresh::class)->request($connection);
                app(\App\Services\Inventory\BackgroundRefresh::class)->request($connection, true);
            });

        return self::SUCCESS;
    }
}
