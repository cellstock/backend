<?php

namespace App\Services\Dashboard;

use App\Enums\MarketplaceConnectionStatus;
use App\Http\Resources\OrderResource;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Services\Orders\MarketplaceOrderVisibility;

class DashboardService
{
    public function __construct(
        private readonly MarketplaceOrderVisibility $visibility
    ) {
    }

    public function forUser(User $user): array
    {
        $orders = $this->visibility->apply(MarketplaceOrder::query(), $user);
        $monthStart = now()->startOfMonth();
        $nextMonth = $monthStart->copy()->addMonth();

        $connections = MarketplaceConnection::query()
            ->where('user_id', $user->id)
            ->get()->keyBy('marketplace_id');

        $visibleOrderCounts = (clone $orders)
            ->where('ordered_at', '>=', $monthStart)
            ->where('ordered_at', '<', $nextMonth)
            ->selectRaw('marketplace_connection_id, COUNT(*) as total')
            ->groupBy('marketplace_connection_id')
            ->pluck('total', 'marketplace_connection_id');

        $marketplaces = Marketplace::query()
            ->where(function ($query) use ($connections) {
                $query->where('is_active', true)
                    ->orWhereIn('id', $connections->keys());
            })
            ->orderBy('display_order')->orderBy('name')->get()
            ->map(function (Marketplace $marketplace) use ($connections, $visibleOrderCounts) {
                $connection = $connections->get($marketplace->id);

                return [
                    'id' => $marketplace->id,
                    'name' => $marketplace->name,
                    'slug' => $marketplace->slug,
                    'ordersThisMonth' => $connection
                        ? (int) ($visibleOrderCounts[$connection->id] ?? 0)
                        : 0,
                    'status' => $connection?->status->value ?? 'disconnected',
                ];
            });

        return [
            'summary' => [
                'totalOrders' => (clone $orders)->count(),
                'pendingOrders' => (clone $orders)
                    ->whereRaw('UPPER(status) IN (?, ?)', ['NEW', 'PENDING'])->count(),
                'connectedMarketplaces' => $connections->filter(
                    fn ($connection) => $connection->status === MarketplaceConnectionStatus::Connected
                )->count(),
                'orderValues' => (clone $orders)
                    ->selectRaw('currency, SUM(total_amount) as total')
                    ->groupBy('currency')->orderBy('currency')->get()
                    ->map(fn ($row) => ['currency' => $row->currency, 'total' => (string) $row->total]),
            ],
            'recentOrders' => OrderResource::collection(
                (clone $orders)->with('connection.marketplace')
                    ->orderByDesc('ordered_at')->orderByDesc('id')->limit(5)->get()
            ),
            'marketplaces' => $marketplaces,
            'updatedAt' => now()->toISOString(),
        ];
    }
}
