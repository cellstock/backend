<?php
namespace App\Jobs;

use App\Models\MarketplaceConnection;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRefurbedInventory implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $timeout = 900;
    public int $tries = 3;
    public int $uniqueFor = 960;
    public function __construct(public readonly int $connectionId) { $this->onQueue('marketplaces'); }
    public function uniqueId(): string { return (string) $this->connectionId; }
    public function handle(InventoryService $inventory): void
    {
        $connection = MarketplaceConnection::with('user')->where('status', 'connected')->find($this->connectionId);
        if (! $connection || ! $connection->user) return;
        $inventory->synchronize($connection->user);
    }
}
