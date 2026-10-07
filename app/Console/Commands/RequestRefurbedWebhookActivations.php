<?php

namespace App\Console\Commands;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\RefurbedWebhookService;
use Illuminate\Console\Command;

class RequestRefurbedWebhookActivations extends Command
{
    protected $signature = 'refurbed:webhooks:request-activation';

    protected $description = 'Request webhook activation for eligible Refurbed connections';

    public function handle(RefurbedWebhookService $webhooks): int
    {
        $connections = MarketplaceConnection::query()
            ->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))
            ->get();

        $requested = 0;

        foreach ($connections as $connection) {
            if ($webhooks->ensureActivationRequested($connection)) {
                $requested++;
            }
        }

        $this->info("Queued {$requested} activation request(s).");

        return self::SUCCESS;
    }
}
