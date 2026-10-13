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

class SyncRefurbedReferenceData implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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

        $this->syncAddresses($connection, $connector);
        $this->syncReturns($connection, $connector);
        $shippingProfiles->sync($connection->user);
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);
    }

    private function syncAddresses(MarketplaceConnection $connection, RefurbedConnector $connector): void
    {
        foreach ($connector->listMerchantAddressesForConnection($connection) as $address) {
            if (! is_array($address) || empty($address['id'])) {
                continue;
            }

            MarketplaceMerchantAddress::updateOrCreate([
                'marketplace_connection_id' => $connection->id,
                'external_id' => (string) $address['id'],
            ], $this->addressValues($address));
        }
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

    private function addressValues(array $address): array
    {
        return [
            'version' => $address['version'] ?? null,
            'label' => $address['label'] ?? null,
            'first_name' => $address['first_name'] ?? null,
            'family_name' => $address['family_name'] ?? null,
            'company_name' => $address['company_name'] ?? null,
            'country_code' => $address['country_code'] ?? null,
            'post_code' => $address['post_code'] ?? null,
            'town' => $address['town'] ?? null,
            'street_name' => $address['street_name'] ?? null,
            'house_no' => $address['house_no'] ?? null,
            'supplement' => $address['supplement'] ?? null,
            'phone_number' => $address['phone_number'] ?? null,
            'type' => $address['type'] ?? null,
            'raw_payload' => $address,
            'sync_status' => 'synced',
            'sync_error' => null,
            'pending_action' => null,
            'retry_count' => 0,
            'last_synced_at' => now(),
        ];
    }
}
