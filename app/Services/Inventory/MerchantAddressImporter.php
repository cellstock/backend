<?php
namespace App\Services\Inventory;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceMerchantAddress;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;

class MerchantAddressImporter
{
    public function sync(MarketplaceConnection $connection, RefurbedConnector $connector): void
    {
        $lock = Cache::lock('merchant-address-import:'.$connection->id, 180);
        if (! $lock->get()) throw new BusinessRuleException('Addresses are already being fetched. Please try again shortly.', 409);
        try {
            foreach ($connector->listMerchantAddressesForConnection($connection) as $address) {
                if (! is_array($address) || empty($address['id'])) continue;
                DB::transaction(function () use ($connection, $address) {
                    // Serialize imports and preserve local edits waiting to be pushed.
                    $active = MarketplaceConnection::whereKey($connection->id)->lockForUpdate()->first();
                    if (! $active) return;
                    $record = MarketplaceMerchantAddress::firstOrNew([
                        'marketplace_connection_id' => $connection->id,
                        'external_id' => (string) $address['id'],
                    ]);
                    if ($record->pending_action) return;
                    $record->fill($this->addressValues($address))->save();
                });
            }
        } finally {
            $lock->release();
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
