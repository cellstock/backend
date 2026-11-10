<?php

namespace App\Services\Marketplaces\Sync;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Services\Inventory\SharedStockService;
use App\Services\Marketplaces\Mappers\LightspeedOrderMapper;
use Illuminate\Support\Facades\DB;
use Throwable;

class LightspeedOrderSynchronizer
{
    public function __construct(
        private readonly LightspeedOrderMapper $mapper,
        private readonly SharedStockService $sharedStock,
    ) {}

    /**
     * @param  list<array{order: array<string, mixed>, products: list<array<string, mixed>>}>  $orders
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    public function sync(MarketplaceConnection $connection, array $orders): array
    {
        $processedCount = count($orders);
        $successCount = 0;
        $failedCount = 0;

        foreach ($orders as $payload) {
            try {
                $orderData = is_array($payload['order'] ?? null) ? $payload['order'] : $payload;
                $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
                $this->syncOrder($connection, $orderData, $products);
                $successCount++;
            } catch (Throwable $exception) {
                report($exception);
                $failedCount++;
            }
        }

        return [
            'processed_count' => $processedCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @param  list<array<string, mixed>>  $products
     */
    public function syncOrder(
        MarketplaceConnection $connection,
        array $orderData,
        array $products = []
    ): void {
        DB::transaction(function () use ($connection, $orderData, $products): void {
            $mappedOrder = $this->mapper->mapOrder($orderData);
            $orderStatus = (string) $mappedOrder['status'];

            $existing = MarketplaceOrder::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('external_order_id', $mappedOrder['external_order_id'])
                ->lockForUpdate()
                ->first();

            $order = MarketplaceOrder::query()->updateOrCreate(
                [
                    'marketplace_connection_id' => $connection->id,
                    'external_order_id' => $mappedOrder['external_order_id'],
                ],
                $mappedOrder
            );

            $seenItemIds = [];
            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $mappedItem = $this->mapper->mapItem($product, $orderStatus);
                $seenItemIds[] = $mappedItem['external_order_item_id'];

                $order->items()->updateOrCreate(
                    ['external_order_item_id' => $mappedItem['external_order_item_id']],
                    $mappedItem
                );
            }

            if ($seenItemIds !== []) {
                $order->items()
                    ->whereNotIn('external_order_item_id', $seenItemIds)
                    ->delete();
            }

            if (! $existing && $connection->user) {
                $order->load('items');
                foreach ($order->items->groupBy('sku') as $sku => $items) {
                    $qty = (int) $items->sum('quantity');
                    if ($sku && $qty > 0) {
                        $this->sharedStock->adjustBySku($connection->user, (string) $sku, -$qty);
                    }
                }
            }
        });
    }
}
