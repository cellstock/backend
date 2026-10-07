<?php

namespace App\Services\Orders;

use App\Models\MarketplaceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceOrderVisibility
{
    public function apply(Builder $query, User $user): Builder
    {
        $connections = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->with('marketplace:id,slug')
            ->get();

        return $query->where(function (Builder $query) use ($connections): void {
            foreach ($connections as $connection) {
                $query->orWhere(function (Builder $query) use ($connection): void {
                    $query->where('marketplace_connection_id', $connection->id);

                    if ($connection->marketplace?->slug !== 'refurbed') {
                        return;
                    }

                    $markets = $connection->settings['markets'] ?? null;

                    if (! is_array($markets)) {
                        return;
                    }

                    $countryCodes = collect($markets)
                        ->filter(fn (mixed $market): bool => is_array($market)
                            && ($market['manage_orders'] ?? false))
                        ->pluck('code')
                        ->filter()
                        ->map(fn (string $code): string => strtoupper($code))
                        ->unique()
                        ->values()
                        ->all();

                    if ($countryCodes === []) {
                        $query->whereRaw('1 = 0');
                        return;
                    }

                    $query->whereIn('country_code', $countryCodes);
                });
            }

            if ($connections->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
