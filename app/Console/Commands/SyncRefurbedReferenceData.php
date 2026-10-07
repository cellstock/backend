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
            ->pluck('id')
            ->each(fn (int $id) => SyncJob::dispatch($id));

        return self::SUCCESS;
    }
}
