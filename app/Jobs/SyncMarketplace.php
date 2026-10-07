<?php

namespace App\Jobs;

use App\Enums\MarketplaceConnectionStatus;
use App\Enums\MarketplaceSyncStatus;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SyncMarketplace implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $syncRunId
    ) {}

    public function backoff(): array
    {
        return [
            60,
            300,
        ];
    }

    public function handle(
        MarketplaceConnectorManager $connectorManager
    ): void {
        $syncRun = MarketplaceSyncRun::query()
            ->with([
                'connection.marketplace',
            ])
            ->findOrFail($this->syncRunId);

        if (
            $syncRun->status
            === MarketplaceSyncStatus::Cancelled
        ) {
            return;
        }

        $connection = $syncRun->connection;

        if (
            ! $connection
            || $connection->status
                !== MarketplaceConnectionStatus::Connected
        ) {
            throw new RuntimeException(
                'The marketplace connection is not active.'
            );
        }

        $syncRun->update([
            'status' => MarketplaceSyncStatus::Running,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $connector = $connectorManager->connector(
            $connection->marketplace
        );

        $result = $connector->sync(
            connection: $connection,
            type: $syncRun->type->value,
            settings: $connection->settings ?? [],
        );

        $syncRun->update([
            'status' => MarketplaceSyncStatus::Completed,
            'processed_count' => $result['processed_count'],
            'success_count' => $result['success_count'],
            'failed_count' => $result['failed_count'],
            'completed_at' => now(),
            'error_message' => null,
        ]);

        $connection->update([
            'last_synced_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(
        ?Throwable $exception
    ): void {
        $syncRun = MarketplaceSyncRun::query()->find($this->syncRunId);

        if (! $syncRun) {
            return;
        }

        $message = $exception?->getMessage() ?: 'Marketplace synchronization failed.';

        $syncRun->update([
            'status' => MarketplaceSyncStatus::Failed,
            'completed_at' => now(),
            'error_message' => $message,
        ]);

        $syncRun->connection?->update([
            'last_error' => $message,
        ]);
    }
}
