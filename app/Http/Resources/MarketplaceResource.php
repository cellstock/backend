<?php

namespace App\Http\Resources;

use App\Models\MarketplaceConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MarketplaceConnection|null $connection */
        $connection = $this->relationLoaded('connection')
            ? $this->getRelation('connection')
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'description' => $this->description,
            'capabilities' => $this->capabilities ?? [],
            'is_active' => $this->is_active,
            'is_connected' => $connection !== null,

            'connection' => $this->when(
                $connection !== null,
                function () use ($connection) {
                    return [
                        'id' => $connection->id,
                        'account_name' =>
                            $connection->account_name,
                        'external_account_id' =>
                            $connection->external_account_id,
                        'status' =>
                            $connection->status->value,
                        'status_label' =>
                            $connection->status->label(),
                        'settings' =>
                            $connection->settings ?? [],
                        'last_tested_at' =>
                            $connection
                                ->last_tested_at
                                ?->toISOString(),
                        'last_synced_at' =>
                            $connection
                                ->last_synced_at
                                ?->toISOString(),
                        'last_error' =>
                            $connection->last_error,
                        'created_at' =>
                            $connection
                                ->created_at
                                ?->toISOString(),
                    ];
                }
            ),
        ];
    }
}