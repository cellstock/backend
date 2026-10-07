<?php

namespace App\Jobs;

use App\Models\MarketplaceMerchantAddress;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushMerchantAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $addressId) {}

    public function handle(MarketplaceConnectorManager $manager): void
    {
        $address = MarketplaceMerchantAddress::with('connection.marketplace')->find($this->addressId);

        if (! $address || ! $address->pending_action) {
            return;
        }

        $connector = $manager->connector($address->connection->marketplace);

        if (! $connector instanceof RefurbedConnector) {
            return;
        }

        try {
            if ($address->pending_action === 'delete') {
                $connector->deleteMerchantAddress($address->connection, $address->external_id);
                $address->delete();

                return;
            }

            $payload = $address->only([
                'type', 'label', 'first_name', 'family_name', 'company_name',
                'country_code', 'post_code', 'town', 'street_name', 'house_no',
                'supplement', 'phone_number',
            ]);
            $remote = $address->pending_action === 'create'
                ? $connector->createMerchantAddress($address->connection, $payload)
                : $connector->updateMerchantAddress($address->connection, $address->external_id, $payload);

            $address->update([
                'external_id' => (string) ($remote['id'] ?? $address->external_id),
                'version' => $remote['version'] ?? $address->version,
                'raw_payload' => $remote,
                'sync_status' => 'synced',
                'sync_error' => null,
                'pending_action' => null,
                'retry_count' => 0,
                'last_synced_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $address->update([
                'sync_status' => 'failed',
                'sync_error' => $exception->getMessage(),
                'retry_count' => $address->retry_count + 1,
            ]);
            throw $exception;
        }
    }
}
