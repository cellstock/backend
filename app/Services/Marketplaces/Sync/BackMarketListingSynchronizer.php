<?php

namespace App\Services\Marketplaces\Sync;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;

class BackMarketListingSynchronizer
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
     * @param  array<string, mixed>  $listing
     */
    public function persist(MarketplaceConnection $connection, array $listing): MarketplaceOffer
    {
        $externalId = (string) ($listing['listing_id'] ?? $listing['id'] ?? '');
        if ($externalId === '') {
            throw new \RuntimeException('Back Market listing ID is missing.');
        }

        $country = strtoupper((string) (
            $connection->credentials['country_code']
            ?? data_get($listing, 'shippings.0.country_code')
            ?? 'FR-FR'
        ));
        $marketCode = strtoupper(explode('-', strtolower($country))[1] ?? explode('-', strtolower($country))[0] ?? 'FR');

        $grade = $this->mapGrade($listing['grade'] ?? $listing['state'] ?? null);
        $publication = (int) ($listing['publication_state'] ?? 0);

        return MarketplaceOffer::query()->updateOrCreate(
            [
                'marketplace_connection_id' => $connection->id,
                'external_offer_id' => $externalId,
            ],
            [
                'instance_id' => isset($listing['product_id']) ? (string) $listing['product_id'] : (isset($listing['backmarket_id']) ? (string) $listing['backmarket_id'] : null),
                'instance_name' => $listing['title'] ?? $listing['sku'] ?? null,
                'ean' => $listing['ean'] ?? $listing['gtin'] ?? null,
                'sku' => $listing['sku'] ?? null,
                'state' => $publication === 2 ? 'OK' : 'UNVERIFIED',
                'grading' => $grade,
                'warranty' => isset($listing['warranty_delay']) ? 'M'.(int) $listing['warranty_delay'] : null,
                'stock' => max(0, (int) ($listing['quantity'] ?? 0)),
                'shipping_profile_id' => null,
                'reference_currency_code' => $listing['currency'] ?? 'EUR',
                'reference_price' => (string) ($listing['price'] ?? '0'),
                'reference_min_price' => isset($listing['min_price']) ? (string) $listing['min_price'] : null,
                'market_codes' => [$marketCode],
                'raw_payload' => $listing,
                'marketplace_created_at' => $listing['date_creation'] ?? null,
                'last_synced_at' => now(),
                'pending_upload' => false,
                'is_active' => $publication === 2 && (int) ($listing['quantity'] ?? 0) > 0,
            ]
        );
    }

    private function mapGrade(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return match ((int) $value) {
                9 => 'AA',
                10 => 'A',
                11 => 'B',
                12 => 'C',
                default => null,
            };
        }

        $normalized = strtoupper(str_replace([' ', '-'], '_', (string) $value));

        return match ($normalized) {
            'PREMIUM', 'STALLONE' => 'AA',
            'EXCELLENT' => 'A',
            'VERY_GOOD', 'GOOD' => 'B',
            'FAIR' => 'C',
            default => null,
        };
    }
}
