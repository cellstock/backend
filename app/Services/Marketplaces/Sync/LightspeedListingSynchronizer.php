<?php

namespace App\Services\Marketplaces\Sync;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;

class LightspeedListingSynchronizer
{
    /**
     * @param  list<array<string, mixed>>  $listings
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    public function sync(MarketplaceConnection $connection, array $listings): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;

        foreach ($listings as $listing) {
            if (! is_array($listing)) {
                continue;
            }

            $processed++;

            try {
                $this->persist($connection, $listing);
                $success++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return [
            'processed_count' => $processed,
            'success_count' => $success,
            'failed_count' => $failed,
        ];
    }

    /**
     * Persist one Lightspeed variant as a marketplace offer.
     *
     * Expected shape:
     * [
     *   'product' => [...],
     *   'variant' => [...],
     * ]
     *
     * @param  array<string, mixed>  $listing
     */
    public function persist(MarketplaceConnection $connection, array $listing): MarketplaceOffer
    {
        $variant = is_array($listing['variant'] ?? null) ? $listing['variant'] : $listing;
        $product = is_array($listing['product'] ?? null) ? $listing['product'] : [];

        $externalId = (string) ($variant['id'] ?? '');
        if ($externalId === '') {
            throw new \RuntimeException('Lightspeed variant ID is missing.');
        }

        $productId = (string) (
            $product['id']
            ?? data_get($variant, 'product.resource.id')
            ?? ''
        );

        $productTitle = trim((string) (
            $product['fulltitle']
            ?? $product['title']
            ?? ''
        ));
        $variantTitle = trim((string) ($variant['title'] ?? ''));
        $instanceName = $productTitle !== '' && $variantTitle !== '' && strcasecmp($variantTitle, 'Default') !== 0
            ? $productTitle.' — '.$variantTitle
            : ($productTitle !== '' ? $productTitle : ($variantTitle !== '' ? $variantTitle : 'Lightspeed variant '.$externalId));

        $sku = trim((string) ($variant['sku'] ?? $variant['articleCode'] ?? ''));
        if ($sku === '') {
            $sku = 'LS-'.$externalId;
        }

        $stock = max(0, (int) ($variant['stockLevel'] ?? 0));
        $isVisible = (bool) ($product['isVisible'] ?? true);
        $price = $variant['priceIncl'] ?? $variant['priceExcl'] ?? 0;

        return MarketplaceOffer::query()->updateOrCreate(
            [
                'marketplace_connection_id' => $connection->id,
                'external_offer_id' => $externalId,
            ],
            [
                'instance_id' => $productId !== '' ? $productId : null,
                'instance_name' => $instanceName,
                'ean' => ($variant['ean'] ?? '') !== '' ? (string) $variant['ean'] : null,
                'sku' => $sku,
                'state' => $isVisible ? 'OK' : 'UNVERIFIED',
                'grading' => null,
                'warranty' => null,
                'stock' => $stock,
                'shipping_profile_id' => null,
                'reference_currency_code' => 'EUR',
                'reference_price' => (string) $price,
                'reference_min_price' => null,
                'market_codes' => ['NL'],
                'raw_payload' => [
                    'product' => $product,
                    'variant' => $variant,
                ],
                'marketplace_created_at' => $variant['createdAt'] ?? $product['createdAt'] ?? null,
                'last_synced_at' => now(),
                'pending_upload' => false,
                'is_active' => $isVisible && $stock > 0,
                'tags' => ['ipad', 'lightspeed'],
            ]
        );
    }
}
