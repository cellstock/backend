<?php

namespace App\Services\Marketplaces;

use App\Jobs\ProcessMarketplaceOperation;
use App\Models\MarketplaceOperation;
use App\Models\User;

class MarketplaceOperationService
{
    public function queue(
        User $user,
        int $connectionId,
        string $type,
        string $entityType,
        string|int|null $entityId,
        array $payload = [],
    ): MarketplaceOperation {
        $operation = MarketplaceOperation::create([
            'user_id' => $user->id,
            'marketplace_connection_id' => $connectionId,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        ProcessMarketplaceOperation::dispatch($operation->id);

        return $operation;
    }
}
