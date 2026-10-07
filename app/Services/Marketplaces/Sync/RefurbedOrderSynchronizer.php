<?php

namespace App\Services\Marketplaces\Sync;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceOrder;
use App\Services\Marketplaces\Mappers\RefurbedOrderMapper;
use Illuminate\Support\Facades\DB;

class RefurbedOrderSynchronizer
{
    public function __construct(
        private readonly RefurbedOrderMapper $mapper
    ) {
    }

    public function synchronize(
        MarketplaceConnection $connection,
        array $source,
        bool $adjustStock = false,
    ): MarketplaceOrder {
        return DB::transaction(function () use (
            $connection,
            $source,
            $adjustStock,
        ): MarketplaceOrder {
            $orderData = $this->mapper->mapOrder($source);

            $existingOrder = MarketplaceOrder::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('external_order_id', $orderData['external_order_id'])
                ->lockForUpdate()
                ->first();
            $previousStatus = strtoupper((string) $existingOrder?->status);

            $order = MarketplaceOrder::query()->updateOrCreate(
                [
                    'marketplace_connection_id' => $connection->id,
                    'external_order_id' =>
                        $orderData['external_order_id'],
                ],
                $orderData
            );

            if (array_key_exists('items', $source)) {
                $this->synchronizeItems($order, $source);
            }

            if ($adjustStock && $order->wasRecentlyCreated) {
                $this->changeOfferStock($connection, $order, decrease: true);
            } elseif ($adjustStock) {
                $currentStatus = strtoupper((string) $order->status);
                $wasTerminal = $this->isTerminalStatus($previousStatus);
                $isTerminal = $this->isTerminalStatus($currentStatus);

                if (! $wasTerminal && $isTerminal) {
                    $this->changeOfferStock($connection, $order, decrease: false);
                } elseif ($wasTerminal && ! $isTerminal) {
                    $this->changeOfferStock($connection, $order, decrease: true);
                }
            }

            return $order->load('items');
        });
    }

    private function changeOfferStock(
        MarketplaceConnection $connection,
        MarketplaceOrder $order,
        bool $decrease,
    ): void {
        $quantities = $order->items
            ->reject(fn ($item) => in_array(
                strtoupper((string) $item->status),
                ['REJECTED', 'CANCELLED', 'RETURNED'],
                true,
            ))
            ->groupBy('sku')
            ->map(fn ($items) => $items->sum('quantity'));

        foreach ($quantities as $sku => $quantity) {
            $offer = MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('sku', $sku)
                ->lockForUpdate()
                ->first();

            if (! $offer) {
                continue;
            }

            $offer->update([
                'stock' => $decrease
                    ? max(0, $offer->stock - (int) $quantity)
                    : $offer->stock + (int) $quantity,
            ]);
        }
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['REJECTED', 'CANCELLED', 'RETURNED', 'REFUNDED'], true);
    }

    private function synchronizeItems(
        MarketplaceOrder $order,
        array $source
    ): void {
        $items = $this->mapper->mapItems($source);
        $externalItemIds = [];

        foreach ($items as $itemData) {
            $externalItemId =
                $itemData['external_order_item_id'];

            $externalItemIds[] = $externalItemId;

            $order->items()->updateOrCreate(
                [
                    'external_order_item_id' =>
                        $externalItemId,
                ],
                $itemData
            );
        }

        if ($externalItemIds === []) {
            $order->items()->delete();

            return;
        }

        $order->items()
            ->whereNotIn(
                'external_order_item_id',
                $externalItemIds
            )
            ->delete();
    }
}
