<?php

namespace App\Services\Inventory;

use App\Models\MarketplaceOffer;
use App\Models\User;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SharedStockService
{
    public function __construct(
        private readonly MarketplaceOperationService $operations,
    ) {
    }

    /**
     * Apply a stock delta to every offer with the same SKU across the user's
     * marketplace connections (Refurbed, Back Market, …), then queue remote updates.
     *
     * Lightspeed is excluded: many variants share one SKU (e.g. condition rows)
     * and must keep independent stock levels.
     */
    public function adjustBySku(User $user, string $sku, int $delta): void
    {
        $sku = trim($sku);
        if ($sku === '' || $delta === 0) {
            return;
        }

        DB::transaction(function () use ($user, $sku, $delta): void {
            $offers = $this->sharedSkuOffersQuery($user, $sku)
                ->lockForUpdate()
                ->get();

            foreach ($offers as $offer) {
                $stock = max(0, (int) $offer->stock + $delta);
                $offer->update([
                    'stock' => $stock,
                    'pending_upload' => true,
                ]);
                $this->operations->queue(
                    $user,
                    $offer->marketplace_connection_id,
                    'offer.update',
                    'offer',
                    $offer->external_offer_id,
                    ['stock' => $stock],
                );
            }
        });
    }

    /**
     * Set the absolute stock for every offer with the same SKU for this user.
     *
     * Lightspeed variants that share a SKU are not updated together.
     */
    public function setBySku(User $user, string $sku, int $stock, ?string $exceptExternalOfferId = null): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            return;
        }

        $stock = max(0, $stock);

        DB::transaction(function () use ($user, $sku, $stock, $exceptExternalOfferId): void {
            $offers = $this->sharedSkuOffersQuery($user, $sku)
                ->when(
                    $exceptExternalOfferId,
                    fn ($query) => $query->where('external_offer_id', '!=', $exceptExternalOfferId)
                )
                ->lockForUpdate()
                ->get();

            foreach ($offers as $offer) {
                if ((int) $offer->stock === $stock) {
                    continue;
                }

                $offer->update([
                    'stock' => $stock,
                    'pending_upload' => true,
                ]);
                $this->operations->queue(
                    $user,
                    $offer->marketplace_connection_id,
                    'offer.update',
                    'offer',
                    $offer->external_offer_id,
                    ['stock' => $stock],
                );
            }
        });
    }

    /**
     * @return Builder<MarketplaceOffer>
     */
    private function sharedSkuOffersQuery(User $user, string $sku): Builder
    {
        return MarketplaceOffer::query()
            ->whereHas('connection', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas(
                'connection.marketplace',
                // Lightspeed condition variants often share one SKU; keep their stock independent.
                fn ($query) => $query->where('slug', '!=', 'lightspeed')
            )
            ->where('sku', $sku);
    }
}
