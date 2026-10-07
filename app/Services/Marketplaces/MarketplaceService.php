<?php

namespace App\Services\Marketplaces;

use App\Enums\MarketplaceConnectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Marketplace;
use App\Models\User;
use App\Models\MarketplaceConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Enums\MarketplaceSyncStatus;
use App\Enums\MarketplaceSyncType;
use App\Jobs\SyncMarketplace;
use App\Models\MarketplaceSyncRun;

use Throwable;
use App\Services\Marketplaces\Connectors\RefurbedConnector;

class MarketplaceService
{
    public function __construct(
        private readonly MarketplaceConnectorManager $connectorManager
    ) {
    }

    /**
     * Return marketplaces with the authenticated user's connections.
     *
     * @return array{
     *     connected: Collection,
     *     available: Collection
     * }
     */
    public function list(User $user): array
    {
        $marketplaces = Marketplace::query()
            ->with([
                'connections' => function ($query) use ($user) {
                    $query->whereBelongsTo($user);
                },
            ])
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(function (Marketplace $marketplace) {
                $connection = $marketplace
                    ->connections
                    ->first();

                $marketplace->setRelation(
                    'connection',
                    $connection
                );

                $marketplace->unsetRelation('connections');

                return $marketplace;
            });

        return [
            'connected' => $marketplaces
                ->filter(
                    fn (Marketplace $marketplace) =>
                        $marketplace->getRelation('connection')
                        !== null
                )
                ->values(),

            'available' => $marketplaces
                ->filter(
                    fn (Marketplace $marketplace) =>
                        $marketplace->getRelation('connection')
                        === null
                )
                ->values(),
        ];
    }

    public function connectionOptions(
        User $user,
        Marketplace $marketplace,
        array $credentials
    ): array {
        $connector = $this->connectorManager->connector($marketplace);

        if ($credentials === []) {
            $credentials = $this->findUserConnection(
                user: $user,
                marketplace: $marketplace,
            )->credentials;
        }

        Validator::make(
            $credentials,
            $connector->credentialRules(),
            [],
            ['api_key' => 'API key']
        )->validate();

        if (! $connector instanceof RefurbedConnector) {
            return ['markets' => []];
        }

        return [
            'markets' => $connector->listMarkets($credentials),
        ];
    }

    public function get(
        User $user,
        Marketplace $marketplace
    ): Marketplace {
        $marketplace->load([
            'connections' => function ($query) use ($user) {
                $query->whereBelongsTo($user);
            },
        ]);

        $connection = $marketplace
            ->connections
            ->first();

        $marketplace->setRelation(
            'connection',
            $connection
        );

        $marketplace->unsetRelation('connections');

        return $marketplace;
    }

    public function connect(
        User $user,
        Marketplace $marketplace,
        array $data
    ): Marketplace {
        if (! $marketplace->is_active) {
            throw new BusinessRuleException(
                message: 'This marketplace is not available.',
                status: 422,
            );
        }

        $connector = $this->connectorManager->connector(
            $marketplace
        );

        $credentials = $data['credentials'];

        Validator::make(
            $credentials,
            $connector->credentialRules(),
            [],
            [
                'api_key' => 'API key',
            ]
        )->validate();

        $existingConnection = MarketplaceConnection::withTrashed()
            ->whereBelongsTo($user)
            ->whereBelongsTo($marketplace)
            ->first();

        if (
            $existingConnection
            && ! $existingConnection->trashed()
            && $existingConnection->status->isConnected()
        ) {
            throw new BusinessRuleException(
                message: 'This marketplace is already connected.',
                status: 409,
            );
        }

        $connection = DB::transaction(function () use (
            $user,
            $marketplace,
            $data,
            $credentials,
            $existingConnection
        ) {
            $connection = $existingConnection
                ?? new MarketplaceConnection();

            if ($connection->exists && $connection->trashed()) {
                $connection->restore();
            }

            $connection->fill([
                'user_id' => $user->id,
                'marketplace_id' => $marketplace->id,
                'account_name' =>
                    $data['account_name'] ?? null,
                'credentials' => $credentials,
                'settings' => $data['settings'] ?? [],
                'status' =>
                    MarketplaceConnectionStatus::Testing,
                'external_account_id' => null,
                'last_tested_at' => null,
                'last_synced_at' => null,
                'last_error' => null,
            ]);

            $connection->save();

            return $connection;
        });

        try {
            $result = $connector->testConnection(
                $credentials
            );

            $connection->update([
                'account_name' =>
                    $data['account_name']
                    ?? $result['account_name']
                    ?? null,
                'external_account_id' =>
                    $result['external_account_id'] ?? null,
                'status' =>
                    MarketplaceConnectionStatus::Connected,
                'last_tested_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $connection->update([
                'status' =>
                    MarketplaceConnectionStatus::Failed,
                'last_tested_at' => now(),
                'last_error' =>
                    'The marketplace connection test failed.',
            ]);

            throw new BusinessRuleException(
                message: 'Unable to connect to the marketplace. Please verify the credentials.',
                status: 422,
            );
        }

        return $this->get(
            user: $user,
            marketplace: $marketplace,
        );
    }

    private function findUserConnection(
        User $user,
        Marketplace $marketplace
    ): MarketplaceConnection {
        $connection = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($marketplace)
            ->first();

        if (! $connection) {
            throw new BusinessRuleException(
                message: 'Marketplace connection not found.',
                status: 404,
            );
        }

        return $connection;
    }

    public function updateConnection(
        User $user,
        Marketplace $marketplace,
        array $data
    ): Marketplace {
        $connection = $this->findUserConnection(
            user: $user,
            marketplace: $marketplace,
        );

        $updateData = [];

        if (array_key_exists('account_name', $data)) {
            $updateData['account_name'] =
                $data['account_name'];
        }

        if (isset($data['settings'])) {
            $updateData['settings'] = array_replace(
                $connection->settings ?? [],
                $data['settings'],
            );
        }

        if (isset($data['credentials'])) {
            $connector = $this->connectorManager->connector(
                $marketplace
            );

            Validator::make(
                $data['credentials'],
                $connector->credentialRules(),
                [],
                [
                    'api_key' => 'API key',
                ]
            )->validate();

            try {
                $result = $connector->testConnection(
                    $data['credentials']
                );

                $updateData['credentials'] =
                    $data['credentials'];

                $updateData['external_account_id'] =
                    $result['external_account_id'] ?? null;

                $updateData['status'] =
                    MarketplaceConnectionStatus::Connected;

                $updateData['last_tested_at'] = now();
                $updateData['last_error'] = null;

                if (
                    ! array_key_exists('account_name', $data)
                    && ! empty($result['account_name'])
                ) {
                    $updateData['account_name'] =
                        $result['account_name'];
                }
            } catch (Throwable $exception) {
                report($exception);

                $connection->update([
                    'last_tested_at' => now(),
                    'last_error' =>
                        'The new marketplace credentials failed validation.',
                ]);

                throw new BusinessRuleException(
                    message: 'The new credentials could not be verified. The existing connection was not changed.',
                    status: 422,
                );
            }
        }

        DB::transaction(function () use (
            $connection,
            $updateData
        ) {
            $connection->update($updateData);
        });

        return $this->get(
            user: $user,
            marketplace: $marketplace,
        );
    }
    public function testConnection(
        User $user,
        Marketplace $marketplace
    ): Marketplace {
        $connection = $this->findUserConnection(
            user: $user,
            marketplace: $marketplace,
        );

        $connector = $this->connectorManager->connector(
            $marketplace
        );

        $connection->update([
            'status' => MarketplaceConnectionStatus::Testing,
            'last_error' => null,
        ]);

        try {
            $result = $connector->testConnection(
                $connection->credentials
            );

            $connection->update([
                'external_account_id' =>
                    $result['external_account_id']
                    ?? $connection->external_account_id,

                'status' =>
                    MarketplaceConnectionStatus::Connected,

                'last_tested_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $connection->update([
                'status' =>
                    MarketplaceConnectionStatus::Failed,
                'last_tested_at' => now(),
                'last_error' =>
                    'The marketplace connection test failed.',
            ]);

            throw new BusinessRuleException(
                message: 'Marketplace connection test failed. Please update the credentials and try again.',
                status: 422,
            );
        }

        return $this->get(
            user: $user,
            marketplace: $marketplace,
        );
    }

    public function disconnect(
        User $user,
        Marketplace $marketplace
    ): void {
        $connection = $this->findUserConnection(
            user: $user,
            marketplace: $marketplace,
        );

        DB::transaction(function () use ($connection) {
            $connection->syncRuns()
                ->whereIn('status', [
                    'queued',
                    'running',
                ])
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'error_message' =>
                        'Marketplace connection was disconnected.',
                ]);

            $connection->update([
                'credentials' => [],
                'settings' => [],
                'external_account_id' => null,
                'status' =>
                    MarketplaceConnectionStatus::Disabled,
                'last_error' => null,
            ]);

            $connection->delete();
        });
    }

    public function sync(
        User $user,
        Marketplace $marketplace,
        MarketplaceSyncType $type
    ): MarketplaceSyncRun {
        $connection = $this->findUserConnection(
            user: $user,
            marketplace: $marketplace,
        );

        if (! $connection->status->isConnected()) {
            throw new BusinessRuleException(
                message: 'The marketplace must be connected before synchronization.',
                status: 422,
            );
        }

        $syncRun = DB::transaction(function () use ($user, $connection, $type) {
            $existingRun = MarketplaceSyncRun::query()
                ->whereBelongsTo($user)
                ->where('marketplace_connection_id', $connection->id)
                ->where('type', $type->value)
                ->whereIn('status', [
                    MarketplaceSyncStatus::Queued->value,
                    MarketplaceSyncStatus::Running->value,
                ])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingRun && ! $this->isAbandonedSyncRun($existingRun)) {
                throw new BusinessRuleException(
                    message: 'A synchronization of this type is already in progress.',
                    status: 409,
                );
            }

            if ($existingRun) {
                $existingRun->update([
                    'status' => MarketplaceSyncStatus::Failed,
                    'completed_at' => now(),
                    'error_message' => 'The synchronization stopped before completion and was replaced by a new run.',
                ]);
            }

            return MarketplaceSyncRun::create([
                'user_id' => $user->id,
                'marketplace_connection_id' => $connection->id,
                'type' => $type,
                'status' => MarketplaceSyncStatus::Queued,
            ]);
        });

        SyncMarketplace::dispatch($syncRun->id)->afterCommit();

        return $syncRun->refresh();
    }

    private function isAbandonedSyncRun(MarketplaceSyncRun $syncRun): bool
    {
        if ($syncRun->status === MarketplaceSyncStatus::Queued) {
            return $syncRun->created_at?->lt(now()->subMinutes(15)) ?? true;
        }

        return $syncRun->started_at?->lt(now()->subHour()) ?? true;
    }

    public function syncRun(
        User $user,
        Marketplace $marketplace,
        MarketplaceSyncRun $syncRun
    ): MarketplaceSyncRun {
        $connection = $this->findUserConnection($user, $marketplace);

        if (
            $syncRun->user_id !== $user->id
            || $syncRun->marketplace_connection_id !== $connection->id
        ) {
            throw new BusinessRuleException(
                message: 'Marketplace synchronization not found.',
                status: 404,
            );
        }

        return $syncRun;
    }
            
}
