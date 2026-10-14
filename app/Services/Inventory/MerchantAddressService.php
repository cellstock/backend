<?php

namespace App\Services\Inventory;

use App\Enums\MarketplaceConnectionStatus;
use App\Jobs\PushMerchantAddress;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceMerchantAddress;
use App\Models\User;
use Illuminate\Support\Str;

class MerchantAddressService
{
    public function fetchFromRefurbed(User $user): array
    {
        $connection = $this->connection($user);
        $connector = app(\App\Services\Marketplaces\MarketplaceConnectorManager::class)->connector($connection->marketplace);
        if (! $connector instanceof \App\Services\Marketplaces\Connectors\RefurbedConnector) {
            throw new \App\Exceptions\BusinessRuleException('A Refurbed connection is required.', 422);
        }
        app(MerchantAddressImporter::class)->sync($connection, $connector);
        return $this->list($user);
    }

    public function list(User $user): array
    {
        $connection = $this->connection($user);

        return MarketplaceMerchantAddress::where('marketplace_connection_id', $connection->id)
            ->where(function ($query): void {
                $query->where('pending_action', '!=', 'delete')
                    ->orWhereNull('pending_action')
                    ->orWhere('sync_status', 'failed');
            })
            ->orderBy('type')
            ->orderBy('label')
            ->get()
            ->map(fn (MarketplaceMerchantAddress $address) => $this->present($address))
            ->all();
    }

    public function create(User $user, array $data): array
    {
        $connection = $this->connection($user);
        $address = MarketplaceMerchantAddress::create([
            'marketplace_connection_id' => $connection->id,
            'external_id' => 'pending-'.Str::uuid(),
            ...$data,
            'sync_status' => 'pending',
            'pending_action' => 'create',
        ]);
        PushMerchantAddress::dispatch($address->id);

        return $this->present($address);
    }

    public function update(User $user, int $id, array $data): array
    {
        $address = $this->owned($user, $id);
        $address->update([
            ...$data,
            'sync_status' => 'pending',
            'sync_error' => null,
            'pending_action' => str_starts_with($address->external_id, 'pending-') ? 'create' : 'update',
        ]);
        PushMerchantAddress::dispatch($address->id);

        return $this->present($address->refresh());
    }

    public function delete(User $user, int $id): void
    {
        $address = $this->owned($user, $id);

        if (str_starts_with($address->external_id, 'pending-')) {
            $address->delete();

            return;
        }

        $address->update(['sync_status' => 'pending', 'pending_action' => 'delete']);
        PushMerchantAddress::dispatch($address->id);
    }

    private function owned(User $user, int $id): MarketplaceMerchantAddress
    {
        return MarketplaceMerchantAddress::where('marketplace_connection_id', $this->connection($user)->id)
            ->findOrFail($id);
    }

    private function connection(User $user): MarketplaceConnection
    {
        return MarketplaceConnection::whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))
            ->firstOrFail();
    }

    private function present(MarketplaceMerchantAddress $address): array
    {
        return [
            'id' => $address->id,
            'external_id' => $address->external_id,
            ...$address->only([
                'version', 'label', 'first_name', 'family_name', 'company_name',
                'country_code', 'post_code', 'town', 'street_name', 'house_no',
                'supplement', 'phone_number', 'type', 'sync_status', 'sync_error',
                'pending_action', 'retry_count',
            ]),
            'last_synced_at' => $address->last_synced_at?->toISOString(),
        ];
    }
}
