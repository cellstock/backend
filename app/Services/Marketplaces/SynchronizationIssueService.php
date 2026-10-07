<?php

namespace App\Services\Marketplaces;

use App\Enums\MarketplaceSyncStatus;
use App\Jobs\PushMerchantAddress;
use App\Jobs\SyncRefurbedReferenceData;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceMerchantAddress;
use App\Models\MarketplaceOperation;
use App\Models\MarketplaceSyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SynchronizationIssueService
{
    public function __construct(private readonly MarketplaceService $marketplaces) {}

    public function list(User $user): array
    {
        $syncRuns = MarketplaceSyncRun::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceSyncStatus::Failed->value)
            ->with('connection.marketplace')
            ->latest('completed_at')
            ->limit(100)
            ->get()
            ->map(fn (MarketplaceSyncRun $run) => [
                'id' => "sync_run:{$run->id}",
                'kind' => 'sync_run',
                'title' => ucfirst($run->type->value).' synchronization failed',
                'marketplace' => $run->connection?->marketplace?->name ?? 'Marketplace',
                'account' => $run->connection?->account_name,
                'message' => $run->error_message ?: 'The synchronization did not complete.',
                'occurred_at' => $run->completed_at?->toISOString() ?? $run->updated_at?->toISOString(),
                'retrying' => false,
            ]);

        $addresses = MarketplaceMerchantAddress::query()
            ->whereHas('connection', fn ($query) => $query->whereBelongsTo($user))
            ->where('sync_status', 'failed')
            ->with('connection.marketplace')
            ->latest('updated_at')
            ->get()
            ->map(fn (MarketplaceMerchantAddress $address) => [
                'id' => "merchant_address:{$address->id}",
                'kind' => 'merchant_address',
                'title' => 'Merchant address failed to synchronize',
                'marketplace' => $address->connection?->marketplace?->name ?? 'Refurbed',
                'account' => $address->connection?->account_name,
                'message' => $address->sync_error ?: 'Refurbed rejected this address change.',
                'occurred_at' => $address->updated_at?->toISOString(),
                'retrying' => false,
            ]);

        $connections = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->whereNotNull('last_error')
            ->with('marketplace')
            ->get()
            ->map(fn (MarketplaceConnection $connection) => [
                'id' => "connection:{$connection->id}",
                'kind' => 'connection',
                'title' => 'Marketplace synchronization needs attention',
                'marketplace' => $connection->marketplace?->name ?? 'Marketplace',
                'account' => $connection->account_name,
                'message' => $connection->last_error,
                'occurred_at' => $connection->updated_at?->toISOString(),
                'retrying' => false,
            ]);

        $operations = MarketplaceOperation::query()
            ->whereBelongsTo($user)
            ->where('status', 'failed')
            ->with('connection.marketplace')
            ->latest('completed_at')
            ->get()
            ->map(fn (MarketplaceOperation $operation) => [
                'id' => "operation:{$operation->id}",
                'kind' => 'operation',
                'title' => str_replace(['.', '_'], ' ', ucfirst($operation->type)).' failed',
                'marketplace' => $operation->connection?->marketplace?->name ?? 'Marketplace',
                'account' => $operation->connection?->account_name,
                'message' => $operation->error_message ?: 'The marketplace rejected this operation.',
                'occurred_at' => $operation->completed_at?->toISOString() ?? $operation->updated_at?->toISOString(),
                'retrying' => false,
            ]);

        return $syncRuns
            ->concat($addresses)
            ->concat($operations)
            ->concat($connections)
            ->sortByDesc('occurred_at')
            ->values()
            ->all();
    }

    public function retry(User $user, string $issueId): void
    {
        [$kind, $id] = array_pad(explode(':', $issueId, 2), 2, null);

        if (! ctype_digit((string) $id)) {
            throw new ModelNotFoundException;
        }

        match ($kind) {
            'sync_run' => $this->retrySyncRun($user, (int) $id),
            'merchant_address' => $this->retryAddress($user, (int) $id),
            'connection' => $this->retryConnection($user, (int) $id),
            'operation' => $this->retryOperation($user, (int) $id),
            default => throw new ModelNotFoundException,
        };
    }

    private function retryOperation(User $user, int $id): void
    {
        $operation = MarketplaceOperation::whereBelongsTo($user)->findOrFail($id);
        $operation->update(['status' => 'pending', 'error_message' => null, 'completed_at' => null]);
        \App\Jobs\ProcessMarketplaceOperation::dispatch($operation->id);
    }

    private function retrySyncRun(User $user, int $id): void
    {
        $run = MarketplaceSyncRun::whereBelongsTo($user)
            ->with('connection.marketplace')
            ->findOrFail($id);
        $this->marketplaces->sync($user, $run->connection->marketplace, $run->type);
    }

    private function retryAddress(User $user, int $id): void
    {
        $address = MarketplaceMerchantAddress::whereHas(
            'connection',
            fn ($query) => $query->whereBelongsTo($user)
        )->findOrFail($id);
        $address->update(['sync_status' => 'pending', 'sync_error' => null]);
        PushMerchantAddress::dispatch($address->id);
    }

    private function retryConnection(User $user, int $id): void
    {
        $connection = MarketplaceConnection::whereBelongsTo($user)->findOrFail($id);
        $connection->update(['last_error' => null]);
        SyncRefurbedReferenceData::dispatch($connection->id);
    }
}
