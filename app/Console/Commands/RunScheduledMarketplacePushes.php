<?php

namespace App\Console\Commands;

use App\Jobs\PushMarketplaceInventory;
use App\Services\Inventory\MarketplacePushService;
use Illuminate\Console\Command;

class RunScheduledMarketplacePushes extends Command
{
    protected $signature = 'marketplaces:run-scheduled-pushes';

    protected $description = 'Queue inventory pushes for marketplaces whose push schedule is due';

    public function handle(MarketplacePushService $pushes): int
    {
        $queued = 0;

        foreach ($pushes->duePreferences() as $preference) {
            PushMarketplaceInventory::dispatch($preference->id);
            $queued++;
        }

        $this->info("Queued {$queued} marketplace inventory push job(s).");

        return self::SUCCESS;
    }
}
