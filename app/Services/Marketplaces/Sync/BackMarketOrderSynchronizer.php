<?php

namespace App\Services\Marketplaces\Sync;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Services\Inventory\SharedStockService;
use App\Services\Marketplaces\Mappers\BackMarketOrderMapper;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackMarketOrderSynchronizer
{
    public function __construct(
        private readonly BackMarketOrderMapper $mapper,
        private readonly SharedStockService $sharedStock,
    ) {
    }

    /**
     * @return array{
     *     processed_count: int,
     *     success_count: int,
     *     failed_count: int
     * }
     */
    public function sync(
        MarketplaceConnection $connection,
        array $orders
    ): array {
        $processedCount = count($orders);
        $successCount = 0;
        $failedCount = 0;

        foreach ($orders as $orderData) {
            try {
                $this->syncOrder(
                    connection: $connection,
                    orderData: $orderData,
                );

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

    public function syncOrder(
        MarketplaceConnection $connection,
        array $orderData
    ): void {
        DB::transaction(function () use (
            $connection,
            $orderData
        ): void {
            $mappedOrder = $this->mapper->mapOrder(
                $orderData
            );

            $existing = MarketplaceOrder::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('external_order_id', $mappedOrder['external_order_id'])
                ->lockForUpdate()
                ->first();

            $order = MarketplaceOrder::query()
                ->updateOrCreate(
                    [
                        'marketplace_connection_id' =>
                            $connection->id,

                        'external_order_id' =>
                            $mappedOrder['external_order_id'],
                    ],
                    $mappedOrder
                );

            foreach (
                $orderData['orderlines'] ?? []
                as $itemData
            ) {
                $mappedItem = $this->mapper->mapItem(
                    $itemData
                );

                $order->items()->updateOrCreate(
                    [
                        'external_order_item_id' =>
                            $mappedItem[
                                'external_order_item_id'
                            ],
                    ],
                    $mappedItem
                );
            }

            // New Back Market orders reduce shared SKU stock on every marketplace.
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