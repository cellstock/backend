<?php

namespace App\Jobs;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceMerchantAddress;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderReturn;
use App\Services\Inventory\ShippingProfileService;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRefurbedReferenceData implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 900;
    public int $uniqueFor = 960;

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function __construct(public readonly int $connectionId) {}

    public function handle(
        MarketplaceConnectorManager $manager,
        ShippingProfileService $shippingProfiles,
    ): void {
        $connection = MarketplaceConnection::with(['marketplace', 'user'])->find($this->connectionId);

        if (! $connection) {
            return; // The connection and its local records may have been deleted.
        }
        $connector = $manager->connector($connection->marketplace);

        if (! $connector instanceof RefurbedConnector) {
            return;
        }

        app(\App\Services\Inventory\MerchantAddressImporter::class)->sync($connection, $connector);
        $this->syncReturns($connection, $connector);
        $shippingProfiles->sync($connection->user);
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);
    }

    private function syncReturns(MarketplaceConnection $connection, RefurbedConnector $connector): void
    {
        foreach ($connector->listOrderItemReturnsForConnection($connection) as $itemReturn) {
            if (! is_array($itemReturn) || empty($itemReturn['id'])) {
                continue;
            }

            $externalOrderId = (string) ($itemReturn['order_id'] ?? '');
            $order = MarketplaceOrder::where('marketplace_connection_id', $connection->id)
                ->where('external_order_id', $externalOrderId)
                ->first();

            MarketplaceOrderReturn::updateOrCreate([
                'marketplace_connection_id' => $connection->id,
                'external_id' => (string) $itemReturn['id'],
            ], [
                'marketplace_order_id' => $order?->id,
                'external_order_id' => $externalOrderId,
                'external_order_item_id' => $itemReturn['order_item_id'] ?? null,
                'instance_name' => $itemReturn['instance_name'] ?? null,
                'state' => $itemReturn['state'] ?? null,
                'return_initiated_at' => $itemReturn['return_initiated_at'] ?? null,
                'delivered_at' => $itemReturn['delivered_at'] ?? null,
                'tracking_number' => $itemReturn['tracking_number'] ?? null,
                'tracking_url' => $itemReturn['tracking_url'] ?? null,
                'ticket_topic' => $itemReturn['ticket_topic'] ?? null,
                'ticket_url' => $itemReturn['ticket_url'] ?? null,
                'item_within_trial_period' => (bool) ($itemReturn['item_within_trial_period'] ?? false),
                'item_identifiers' => $itemReturn['item_identifiers'] ?? [],
                'raw_payload' => $itemReturn,
                'last_synced_at' => now(),
            ]);
        }
    }

}
