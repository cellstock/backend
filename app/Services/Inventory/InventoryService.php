<?php

namespace App\Services\Inventory;

use App\Enums\MarketplaceConnectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\RefurbedCatalogItem;
use App\Models\User;
use App\Services\Marketplaces\Connectors\BackMarketConnector;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Http\UploadedFile;

class InventoryService
{
    public function __construct(
        private readonly MarketplaceConnectorManager $connectors,
        private readonly MarketplaceOperationService $operations,
    ) {}

    public function list(User $user, array $filters): array
    {
        [$connection] = $this->inventoryConnection(
            $user,
            isset($filters['marketplace']) ? (string) $filters['marketplace'] : null
        );
        app(BackgroundRefresh::class)->request($connection, true);
        $markets = $this->offerMarkets($connection);
        $marketCode = strtoupper((string) ($filters['market'] ?? data_get($markets, '0.code', '')));

        if ($marketCode !== '' && ! collect($markets)->contains('code', $marketCode)) {
            throw new BusinessRuleException('This market is not enabled for offer management.', 422);
        }

        $this->backfillOfferMarketCodes($connection);
        $sort = $filters['sort'] ?? 'created_desc';
        [$sortColumn, $sortDirection] = match ($sort) {
            'created_asc' => ['marketplace_created_at', 'asc'],
            'stock_desc' => ['stock', 'desc'],
            'stock_asc' => ['stock', 'asc'],
            'price_desc' => ['reference_price', 'desc'],
            'price_asc' => ['reference_price', 'asc'],
            default => ['marketplace_created_at', 'desc'],
        };

        $paginator = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->when($marketCode !== '', fn ($query) => $query->whereJsonContains('market_codes', $marketCode))
            ->when($filters['search'] ?? null, function ($query, string $search) use ($filters): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $searchBy = $filters['search_by'] ?? 'all';
                $query->where(function ($nested) use ($search, $searchBy): void {
                    if ($searchBy === 'sku') {
                        $nested->where('sku', 'like', $search);
                    } elseif ($searchBy === 'title') {
                        $nested->where('instance_name', 'like', $search);
                    } elseif ($searchBy === 'ean') {
                        $nested->where('ean', 'like', $search);
                    } else {
                        $nested->where('sku', 'like', $search)->orWhere('instance_name', 'like', $search)->orWhere('external_offer_id', 'like', $search)->orWhere('ean', 'like', $search);
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('state', $status))
            ->when($filters['grade'] ?? null, fn ($query, $grade) => $query->where('grading', $grade))
            ->when(($filters['stock'] ?? null) === 'in', fn ($query) => $query->where('stock', '>', 0))
            ->when(($filters['stock'] ?? null) === 'out', fn ($query) => $query->where('stock', 0))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id', 'desc')
            ->paginate($filters['limit'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'offers' => collect($paginator->items())->map(fn (MarketplaceOffer $offer) => [
                'id' => $offer->external_offer_id,
                'created_at' => $offer->marketplace_created_at?->toISOString(),
                'instance_id' => $offer->instance_id,
                'instance_name' => $offer->instance_name,
                'state' => $offer->state,
                'sku' => $offer->sku,
                'grading' => $offer->grading,
                'warranty' => $offer->warranty,
                'taxation' => $this->payloadValue($offer, ['taxation', 'real_taxation']) ?? 'GROSS',
                'stock' => $offer->stock,
                'shipping_profile_id' => $offer->shipping_profile_id,
                'reference_currency_code' => $offer->reference_currency_code,
                'reference_price' => $offer->reference_price,
                'reference_min_price' => $offer->reference_min_price,
                'ean' => $offer->ean,
                'tags' => $offer->tags ?? (array) ($this->payloadValue($offer, ['tags', 'labels']) ?? []),
                'pending_upload' => $offer->pending_upload,
                'is_active' => $offer->is_active,
                'online_price' => $this->payloadValue($offer, ['online_price.amount', 'market_price.amount']) ?? $offer->reference_price,
                'repriced_price' => $this->payloadValue($offer, ['repriced_price.amount']) ?? $offer->reference_min_price,
                'selected_competitor' => $this->payloadValue($offer, ['selected_competitor.name', 'market_data.selected_competitor.name']),
                'sales_rank' => $this->payloadValue($offer, ['sales_rank', 'market_data.sales_rank']),
                'buy_box' => (bool) ($this->payloadValue($offer, ['has_buy_box', 'buy_box', 'market_data.has_buy_box', 'market_data.buy_box']) ?? false),
                'lowest_price' => $this->payloadValue($offer, ['lowest_price.amount', 'market_data.lowest_price.amount']),
                'price_locked' => (bool) ($this->payloadValue($offer, ['price_locked', 'is_price_locked']) ?? false),
                'last_synced_at' => $offer->last_synced_at?->toISOString(),
            ])->all(),
            'marketplace' => [
                'name' => $connection->marketplace->name,
                'slug' => $connection->marketplace->slug,
                'logo' => $connection->marketplace->logo,
                'account_name' => $connection->account_name,
            ],
            'markets' => $markets,
            'selected_market' => $marketCode ?: null,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function payloadValue(MarketplaceOffer $offer, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($offer->raw_payload ?? [], $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function diagnostics(User $user): array
    {
        [$connection] = $this->refurbed($user);
        $groups = [];

        MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->orderBy('id')
            ->each(function (MarketplaceOffer $offer) use (&$groups): void {
                foreach ($this->offerErrors($offer) as $error) {
                    $key = $error['code'].'|'.$error['message'];
                    if (! isset($groups[$key])) {
                        $groups[$key] = [
                            'code' => $error['code'],
                            'message' => $error['message'],
                            'skus' => [],
                        ];
                    }
                    if ($offer->sku && ! in_array($offer->sku, $groups[$key]['skus'], true)) {
                        $groups[$key]['skus'][] = $offer->sku;
                    }
                }
            });

        return [
            'marketplace' => [
                'name' => $connection->marketplace->name,
                'slug' => $connection->marketplace->slug,
                'logo' => $connection->marketplace->logo,
                'account_name' => $connection->account_name,
            ],
            'errors' => array_values($groups),
            'last_synced_at' => $connection->last_synced_at?->toISOString(),
        ];
    }

    private function offerErrors(MarketplaceOffer $offer): array
    {
        $payload = $offer->raw_payload ?? [];
        $candidates = data_get($payload, 'errors')
            ?? data_get($payload, 'validation_errors')
            ?? data_get($payload, 'rejection_reasons')
            ?? data_get($payload, 'status.errors')
            ?? data_get($payload, 'marketplace_errors');
        $errors = [];

        if (is_array($candidates)) {
            $items = array_is_list($candidates) ? $candidates : [$candidates];
            foreach ($items as $index => $item) {
                if (is_string($item) && trim($item) !== '') {
                    $errors[] = ['code' => 'OFFER_ERROR_'.($index + 1), 'message' => trim($item)];
                } elseif (is_array($item)) {
                    $message = $item['message'] ?? $item['description'] ?? $item['detail'] ?? $item['reason'] ?? null;
                    if (is_string($message) && trim($message) !== '') {
                        $errors[] = [
                            'code' => (string) ($item['code'] ?? $item['id'] ?? 'OFFER_ERROR_'.($index + 1)),
                            'message' => trim($message),
                        ];
                    }
                }
            }
        }

        if ($errors === [] && in_array(strtoupper((string) $offer->state), ['REJECTED', 'ERROR', 'INVALID', 'FAILED'], true)) {
            $message = $this->payloadValue($offer, ['error_message', 'rejection_reason', 'status.message', 'state_reason']);
            $errors[] = [
                'code' => strtoupper((string) $offer->state),
                'message' => is_string($message) && trim($message) !== '' ? trim($message) : 'The marketplace rejected this offer. Synchronize it again to retrieve the latest details.',
            ];
        }

        return $errors;
    }

    public function bulk(User $user, array $data): array
    {
        [$connection] = $this->refurbed($user);
        $offers = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->whereIn('external_offer_id', $data['offer_ids'])
            ->get();

        if ($offers->count() !== count(array_unique($data['offer_ids']))) {
            throw new BusinessRuleException('One or more selected offers were not found.', 404);
        }

        $action = $data['action'];
        if (in_array($action, ['inactive', 'out_of_stock'], true)) {
            foreach ($offers as $offer) {
                $offer->update(['stock' => 0, 'is_active' => $action !== 'inactive', 'pending_upload' => true]);
                $this->operations->queue($user, $connection->id, 'offer.update', 'offer', $offer->external_offer_id, ['stock' => 0]);
            }

            return ['updated' => $offers->count(), 'failed' => 0];
        }

        foreach ($offers as $offer) {
            if ($action === 'queue_upload') {
                $offer->pending_upload = true;
            }
            if ($action === 'add_tag') {
                $offer->tags = array_values(array_unique([...($offer->tags ?? []), trim($data['tag'])]));
            }
            if ($action === 'delete_tag') {
                $offer->tags = array_values(array_filter($offer->tags ?? [], fn ($tag) => $tag !== trim($data['tag'])));
            }
            if ($action === 'delete_all_tags') {
                $offer->tags = [];
            }
            $offer->save();
        }

        return ['updated' => $offers->count(), 'failed' => 0];
    }

    public function findInstance(User $user, string $identifier, string $identifierType): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $identifier = trim($identifier);

        if ($identifierType === 'ean_or_sku') {
            $offer = MarketplaceOffer::where('marketplace_connection_id', $connection->id)->where('sku', $identifier)->first();
            if ($offer) {
                if (! $offer->instance_id) throw new BusinessRuleException('This SKU has no linked Refurbed product yet. Search by EAN instead.', 422);
                return [
                    'id' => (string) $offer->instance_id,
                    'name' => $offer->instance_name ?: $offer->sku,
                    'gtin' => $offer->ean,
                    'sku' => $offer->sku,
                ];
            }

            $catalogItem = RefurbedCatalogItem::query()
                ->where('sku', $identifier)
                ->latest('catalog_import_id')
                ->first();
            if ($catalogItem) {
                $remote = $this->normalizeInstance(
                    $connector->getInstance($connection, (string) $catalogItem->instance_id, 'id')
                );

                return [
                    'id' => $remote['id'] !== '' ? $remote['id'] : (string) $catalogItem->instance_id,
                    'name' => $remote['name'] !== '' ? $remote['name'] : ($catalogItem->name ?: $identifier),
                    'name_de' => $catalogItem->name_de,
                    'attributes' => $catalogItem->attributes,
                    'attributes_de' => $catalogItem->attributes_de,
                    'category' => $catalogItem->main_category,
                    'gtin' => $remote['gtin'] ?? null,
                    'sku' => $catalogItem->sku,
                ];
            }

            if (! preg_match('/^\d{8,14}$/', $identifier)) {
                throw new BusinessRuleException('No matching SKU was found in your inventory or imported catalog.', 404);
            }

            return $this->normalizeInstance($connector->getInstance($connection, $identifier, 'gtin'));
        }

        if (in_array($identifierType, ['gtin', 'mpn'], true)) {
            return $this->normalizeInstance($connector->getInstance($connection, $identifier, $identifierType));
        }

        if ($identifierType === 'id') {
            $remote = $this->normalizeInstance($connector->getInstance($connection, $identifier, 'id'));
            $local = RefurbedCatalogItem::query()
                ->where('instance_id', $identifier)
                ->latest('catalog_import_id')
                ->first();

            return [
                'id' => $remote['id'],
                'name' => $remote['name'] ?: ($local?->name ?? $identifier),
                'name_de' => $local?->name_de,
                'attributes' => $local?->attributes,
                'attributes_de' => $local?->attributes_de,
                'category' => $local?->main_category,
                'gtin' => $remote['gtin'] ?? null,
                'sku' => $local?->sku,
            ];
        }

        $item = RefurbedCatalogItem::query()
            ->where('name', 'like', "%{$identifier}%")
            ->latest('catalog_import_id')
            ->first();

        if (! $item) {
            throw new BusinessRuleException('No matching instance exists in the imported Refurbed catalog.', 404);
        }

        return [
            'id' => (string) $item->instance_id,
            'name' => $item->name,
            'name_de' => $item->name_de,
            'attributes' => $item->attributes,
            'attributes_de' => $item->attributes_de,
            'category' => $item->main_category,
        ];
    }

    public function create(User $user, array $data): array
    {
        [$connection, $connector] = $this->inventoryConnection(
            $user,
            isset($data['marketplace']) ? (string) $data['marketplace'] : null
        );
        $listingDetails = $data['listing_details'] ?? null;

        if ($connector instanceof BackMarketConnector) {
            return $this->createBackMarketListing($connection, $connector, $data, $listingDetails);
        }

        $marketCode = strtoupper((string) ($data['market_code'] ?? ''));
        $market = collect($this->offerMarkets($connection))->firstWhere('code', $marketCode);

        if (! $market) {
            throw new BusinessRuleException('This market is not enabled for offer management.', 422);
        }

        $prices = $this->listingMarketPrices($connection, $data);
        $offer = $connector->createOffer($connection, $this->createOfferPayload($data, $prices));
        $stored = $this->persistOffer($connection, $offer);
        if (empty($stored->market_codes)) {
            $stored->market_codes = array_column($prices, 'market_code');
        }
        if ($listingDetails !== null) {
            $stored->listing_details = $listingDetails;
        }
        $stored->save();

        return $offer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>|null  $listingDetails
     * @return array<string, mixed>
     */
    private function createBackMarketListing(
        MarketplaceConnection $connection,
        BackMarketConnector $connector,
        array $data,
        ?array $listingDetails
    ): array {
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            throw new BusinessRuleException('SKU is required for Back Market listings.', 422);
        }

        $quantity = max(0, (int) ($data['stock'] ?? 0));
        $price = (string) ($data['reference_price'] ?? '0');
        $currency = strtoupper((string) ($data['reference_currency_code'] ?? 'EUR'));
        $productId = trim((string) ($data['instance_id'] ?? $data['backmarket_id'] ?? $data['product_id'] ?? ''));
        if ($productId === '') {
            throw new BusinessRuleException(
                'A Back Market product ID (backmarket_id) is required. Find it in the Back Market catalog, then create the listing.',
                422
            );
        }

        $grade = match (strtoupper((string) ($data['grading'] ?? 'A'))) {
            'AA' => 'PREMIUM',
            'A' => 'EXCELLENT',
            'B' => 'GOOD',
            'C' => 'FAIR',
            default => 'EXCELLENT',
        };

        $row = [
            'sku' => $sku,
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
            'min_price' => (string) ($data['reference_min_price'] ?? $price),
            'max_price' => $price,
            'comment' => trim((string) data_get($listingDetails, 'description', '')),
            'warranty_delay' => 12,
            'grade' => $grade,
        ];

        $result = $connector->upsertListings($connection, [$row]);
        $stored = $this->persistOffer($connection, [
            'listing_id' => 'pending-'.$sku,
            'id' => 'pending-'.$sku,
            'sku' => $sku,
            'title' => data_get($listingDetails, 'title') ?: $sku,
            'product_id' => $productId,
            'backmarket_id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
            'min_price' => $row['min_price'],
            'max_price' => $price,
            'currency' => $currency,
            'publication_state' => 1,
            'grade' => $grade,
            'warranty_delay' => 12,
        ]);
        $stored->pending_upload = true;
        if ($listingDetails !== null) {
            $stored->listing_details = $listingDetails;
        }
        $stored->save();

        return [
            'id' => $stored->external_offer_id,
            'sku' => $sku,
            'task_id' => $result['task_id'],
            'pending_upload' => true,
            'marketplace' => 'back-market',
        ];
    }

    /**
     * Build a CreateOffer body with only Refurbed-accepted fields and one product identifier.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, string>>  $prices
     * @return array<string, mixed>
     */
    private function createOfferPayload(array $data, array $prices): array
    {
        $instanceId = preg_replace('/\D+/', '', (string) ($data['instance_id'] ?? '')) ?: '';
        $gtin = preg_replace('/\D+/', '', (string) ($data['gtin'] ?? '')) ?: '';
        $mpn = trim((string) ($data['mpn'] ?? ''));

        $payload = [
            'sku' => trim((string) $data['sku']),
            'grading' => $data['grading'],
            'taxation' => $data['taxation'],
            'warranty' => $data['warranty'],
            'stock' => (int) $data['stock'],
            'shipping_profile_id' => trim((string) $data['shipping_profile_id']),
            'reference_currency_code' => strtoupper((string) $data['reference_currency_code']),
            'reference_price' => (string) $data['reference_price'],
            'create_market_prices' => $prices,
        ];

        if (array_key_exists('reference_min_price', $data) && $data['reference_min_price'] !== null && $data['reference_min_price'] !== '') {
            $payload['reference_min_price'] = (string) $data['reference_min_price'];
        }

        // Prefer a numeric Refurbed instance ID; otherwise GTIN; otherwise MPN.
        if ($instanceId !== '') {
            $payload['instance_id'] = $instanceId;
        } elseif ($gtin !== '') {
            if (! preg_match('/^\d{8}$|^\d{12}$|^\d{13}$/', $gtin)) {
                throw new BusinessRuleException('Enter a valid GTIN-8, GTIN-12, or GTIN-13 (EAN/UPC).', 422);
            }
            $payload['gtin'] = $gtin;
        } elseif ($mpn !== '') {
            $payload['mpn'] = $mpn;
        } else {
            throw new BusinessRuleException('A Refurbed instance ID or a recognized GTIN is required to create an offer.', 422);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $instance
     * @return array<string, mixed>
     */
    private function normalizeInstance(array $instance): array
    {
        $id = $instance['id'] ?? $instance['instance_id'] ?? null;

        return [
            'id' => $id !== null && $id !== '' ? (string) $id : '',
            'name' => (string) ($instance['name'] ?? ''),
            'gtin' => isset($instance['gtin']) ? (string) $instance['gtin'] : (isset($instance['ean']) ? (string) $instance['ean'] : null),
            'mpn' => isset($instance['mpn']) ? (string) $instance['mpn'] : null,
        ];
    }

    public function listingMarketPrices(MarketplaceConnection $connection, array $data): array
    {
        $markets = collect($this->offerMarkets($connection))->keyBy('code');
        $rows = $data['market_prices'] ?? [['market_code' => $data['market_code'], 'price' => $data['reference_price'], 'min_price' => $data['reference_min_price'] ?? $data['reference_price']]];
        $prices = [];
        foreach ($rows as $row) {
            $code = strtoupper($row['market_code']);
            $market = $markets->get($code);
            if (! $market) throw new BusinessRuleException('Offer management is not enabled for market '.$code.'.', 422);
            $price = $row['price'];
            $minimum = $row['min_price'] ?? $price;
            if ((float) $minimum > (float) $price) throw new BusinessRuleException('Minimum price cannot exceed top price for '.$code.'.', 422);
            $prices[] = ['market_code' => $code, 'currency_code' => $market['currency_code'] ?? $data['reference_currency_code'], 'price' => (string) $price, 'min_price' => (string) $minimum];
        }
        return $prices;
    }

    public function getOffer(User $user, string $offerId): array
    {
        [$connection] = $this->refurbed($user);
        $offer = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->where('external_offer_id', $offerId)
            ->firstOrFail();

        return [
            'id' => $offer->external_offer_id,
            'created_at' => $offer->marketplace_created_at?->toISOString(),
            'instance_id' => $offer->instance_id,
            'instance_name' => $offer->instance_name,
            'state' => $offer->state,
            'sku' => $offer->sku,
            'grading' => $offer->grading,
            'warranty' => $offer->warranty,
            'taxation' => $this->payloadValue($offer, ['taxation', 'real_taxation']) ?? 'GROSS',
            'stock' => $offer->stock,
            'shipping_profile_id' => $offer->shipping_profile_id,
            'reference_currency_code' => $offer->reference_currency_code,
            'reference_price' => $offer->reference_price,
            'reference_min_price' => $offer->reference_min_price,
            'ean' => $offer->ean,
            'tags' => $offer->tags ?? [],
            'buy_box' => (bool) ($this->payloadValue($offer, ['has_buy_box', 'buy_box', 'market_data.has_buy_box', 'market_data.buy_box']) ?? false),
            'listing_details' => $offer->listing_details ?? [],
            'market_prices' => array_values(array_replace(
                collect(array_merge($offer->raw_payload['calculated_market_prices'] ?? [], $offer->raw_payload['set_market_prices'] ?? []))->keyBy('market_code')->all(),
                collect(json_decode($offer->listing_details['marketplace_prices'] ?? '[]', true) ?: [])->keyBy('market_code')->all()
            )),
            'market_codes' => $offer->market_codes ?? [],
            'markets' => $this->offerMarkets($connection),
            'marketplace' => [
                'name' => $connection->marketplace->name,
                'slug' => $connection->marketplace->slug,
                'logo' => $connection->marketplace->logo,
                'account_name' => $connection->account_name,
            ],
        ];
    }

    public function update(User $user, string $offerId, array $data): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $stored = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->where('external_offer_id', $offerId)
            ->firstOrFail();
        $listingDetails = $data['listing_details'] ?? null;
        $marketCode = strtoupper((string) ($data['market_code'] ?? ''));
        $marketPrices = $data['market_prices'] ?? null;
        unset($data['listing_details'], $data['market_code'], $data['market_prices']);

        $offerMarketCodes = collect($stored->market_codes ?? [])
            ->map(fn ($code) => strtoupper((string) $code))
            ->filter()
            ->values()
            ->all();
        if ($offerMarketCodes === []) {
            $offerMarketCodes = collect($this->offerMarkets($connection))->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
        }

        if (is_array($marketPrices) && $marketPrices !== []) {
            foreach ($marketPrices as $price) {
                $code = strtoupper((string) ($price['market_code'] ?? ''));
                if ($offerMarketCodes !== [] && ! in_array($code, $offerMarketCodes, true)) {
                    throw new BusinessRuleException('This offer is not available in the selected market.', 422);
                }
            }
            $data['update_market_prices'] = $this->listingMarketPrices($connection, [
                'market_prices' => $marketPrices,
                'reference_currency_code' => $stored->reference_currency_code,
            ]);
        } elseif ($marketCode !== '') {
            $market = collect($this->offerMarkets($connection))->firstWhere('code', $marketCode);
            if (! $market || ($offerMarketCodes !== [] && ! in_array($marketCode, $offerMarketCodes, true))) {
                throw new BusinessRuleException('This offer is not available in the selected market.', 422);
            }

            $data['update_market_prices'] = [[
                'market_code' => $marketCode,
                'currency_code' => $market['currency_code'] ?? $stored->reference_currency_code,
                'price' => (string) ($data['reference_price'] ?? $stored->reference_price),
                'min_price' => (string) ($data['reference_min_price'] ?? $stored->reference_min_price ?? $stored->reference_price),
            ]];
        } elseif (isset($data['reference_price']) || isset($data['reference_min_price'])) {
            // Panel top/bottom price changes must push per-market prices to Refurbed.
            $price = (string) ($data['reference_price'] ?? $stored->reference_price);
            $minimum = (string) ($data['reference_min_price'] ?? $stored->reference_min_price ?? $price);
            $markets = collect($this->offerMarkets($connection))->keyBy('code');
            $data['update_market_prices'] = collect($offerMarketCodes !== [] ? $offerMarketCodes : $markets->keys())
                ->map(function (string $code) use ($markets, $price, $minimum, $stored): array {
                    $market = $markets->get($code);

                    return [
                        'market_code' => $code,
                        'currency_code' => $market['currency_code'] ?? $stored->reference_currency_code,
                        'price' => $price,
                        'min_price' => $minimum,
                    ];
                })
                ->values()
                ->all();
        }

        $preferences = array_replace($stored->listing_details ?? [], $listingDetails ?? []);
        $stopQuantity = false;
        foreach ($offerMarketCodes as $code) {
            $stopQuantity = $stopQuantity || ($preferences['dont_send_quantity_'.$code] ?? '0') === '1';
        }
        if ($stopQuantity) {
            unset($data['stock']);
        }
        if (isset($data['update_market_prices'])) {
            $data['update_market_prices'] = array_values(array_filter(
                $data['update_market_prices'],
                fn ($price) => ($preferences['dont_send_price_'.$price['market_code']] ?? '0') !== '1'
            ));
            foreach ($data['update_market_prices'] as &$price) {
                if (($preferences['block_price_'.$price['market_code']] ?? '0') === '1') {
                    $price['min_price'] = $price['price'];
                }
            }
            unset($price);
            if ($data['update_market_prices'] === []) {
                unset($data['update_market_prices']);
            }
        }
        // Keep reference_price when any market still accepts price updates.
        if (! isset($data['update_market_prices']) && collect($offerMarketCodes)->every(
            fn (string $code) => ($preferences['dont_send_price_'.$code] ?? '0') === '1'
        )) {
            unset($data['reference_price'], $data['reference_min_price']);
        }

        // Refurbed UpdateOffer only accepts a known field set.
        $data = array_intersect_key($data, array_flip([
            'grading', 'warranty', 'stock', 'shipping_profile_id', 'secondary_shipping_profile_id',
            'taxation', 'reference_currency_code', 'reference_price', 'reference_min_price',
            'update_market_prices', 'battery_condition',
        ]));

        $remoteOffer = null;
        if ($data !== []) {
            $remoteOffer = $connector->updateOffer($connection, $offerId, $data);
            $stored = $this->persistOffer($connection, $remoteOffer);
        }
        if ($listingDetails !== null) {
            $stored->forceFill([
                'listing_details' => $listingDetails,
                'pending_upload' => false,
            ])->save();
        } else {
            $stored->forceFill(['pending_upload' => false])->save();
        }

        return $remoteOffer ?? ['id' => $stored->external_offer_id];
    }

    public function delete(User $user, string $offerId): void
    {
        [$connection, $connector] = $this->refurbed($user);
        $connector->deleteOffer($connection, $offerId);
        MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->where('external_offer_id', $offerId)
            ->delete();
    }

    public function importStocks(User $user, UploadedFile $file, string $marketCode): array
    {
        [$connection] = $this->refurbed($user);
        $marketCode = strtoupper(trim($marketCode));
        if (! collect($this->offerMarkets($connection))->contains('code', $marketCode)) {
            throw new BusinessRuleException('This market is not enabled for offer management.', 422);
        }

        $this->backfillOfferMarketCodes($connection);
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new BusinessRuleException('The inventory file could not be read.', 422);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new BusinessRuleException('The inventory file is empty.', 422);
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter);
        $normalized = array_map(
            fn ($value) => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")),
            $header ?: []
        );
        $skuIndex = array_search('sku', $normalized, true);
        $stockIndex = array_search('stock', $normalized, true);

        if ($skuIndex === false || $stockIndex === false) {
            fclose($handle);
            throw new BusinessRuleException('The CSV file must contain sku and stock columns.', 422);
        }

        $stocks = [];
        $failures = [];
        $processed = 0;
        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $processed++;
            $sku = trim((string) ($row[$skuIndex] ?? ''));
            $stock = trim((string) ($row[$stockIndex] ?? ''));
            if ($sku === '') {
                $failures[] = $this->importFailure($rowNumber, '', $stock, 'SKU is required.');

                continue;
            }
            if (filter_var($stock, FILTER_VALIDATE_INT) === false || (int) $stock < 0) {
                $failures[] = $this->importFailure($rowNumber, $sku, $stock, 'Stock must be a whole number of zero or greater.');

                continue;
            }
            if (array_key_exists($sku, $stocks)) {
                $failures[] = $this->importFailure($rowNumber, $sku, $stock, 'Duplicate SKU in the file.');

                continue;
            }
            $stocks[$sku] = (int) $stock;
        }
        fclose($handle);

        if ($processed === 0) {
            throw new BusinessRuleException('The inventory file contains no data rows.', 422);
        }

        $knownSkus = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->whereJsonContains('market_codes', $marketCode)
            ->whereIn('sku', array_keys($stocks))
            ->pluck('sku')
            ->all();
        foreach (array_diff(array_keys($stocks), $knownSkus) as $sku) {
            $failures[] = $this->importFailure(null, $sku, (string) $stocks[$sku], "SKU is not available in Refurbed {$marketCode}.", 404);
            unset($stocks[$sku]);
        }

        foreach ($stocks as $sku => $stock) {
            $offer = MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('sku', $sku)
                ->first();
            if (! $offer) {
                continue;
            }
            $offer->update(['stock' => $stock, 'pending_upload' => true]);
            $this->operations->queue($user, $connection->id, 'offer.update', 'offer', $offer->external_offer_id, ['stock' => $stock]);
        }

        return [
            'market_code' => $marketCode,
            'processed' => $processed,
            'successful' => count($stocks),
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }

    private function importFailure(?int $row, string $sku, string $stock, string $message, int $code = 422): array
    {
        return compact('row', 'sku', 'stock', 'code', 'message');
    }

    public function synchronize(User $user, ?string $marketplaceSlug = null): array
    {
        [$connection, $connector] = $this->inventoryConnection($user, $marketplaceSlug);

        if ($connector instanceof BackMarketConnector) {
            $result = $connector->sync($connection, 'inventory');
            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

            return [
                'synchronized' => (int) ($result['success_count'] ?? 0),
                'complete' => true,
                'marketplace' => 'back-market',
            ];
        }

        $cursor = null;
        $seen = [];
        $count = 0;

        do {
            $result = $connector->listOffers($connection, $cursor, 100);
            foreach ($result['offers'] as $offer) {
                if (! is_array($offer)) {
                    continue;
                }
                $pending = MarketplaceOffer::where('marketplace_connection_id', $connection->id)
                    ->where('external_offer_id', (string) ($offer['id'] ?? data_get($offer, 'identifier.id', '')))
                    ->where('pending_upload', true)->first();
                $stored = $pending ?? $this->persistOffer($connection, $offer);
                $seen[] = $stored->external_offer_id;
                $count++;
            }
            $cursor = $result['next_cursor'] ?: null;
        } while ($result['has_more'] && $cursor !== null);

        if (! $result['has_more']) {
            MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('pending_upload', false)
                ->when($seen !== [], fn ($query) => $query->whereNotIn('external_offer_id', $seen))
                ->when($seen === [], fn ($query) => $query)
                ->delete();
        }

        $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return ['synchronized' => $count, 'complete' => ! $result['has_more']];
    }

    private function refurbed(User $user): array
    {
        return $this->inventoryConnection($user, 'refurbed');
    }

    /**
     * @return array{0: MarketplaceConnection, 1: RefurbedConnector|BackMarketConnector}
     */
    private function inventoryConnection(User $user, ?string $preferSlug = null): array
    {
        $connections = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->whereIn('slug', ['refurbed', 'back-market']))
            ->with('marketplace')
            ->get();

        $connection = null;
        if ($preferSlug) {
            $connection = $connections->first(
                fn (MarketplaceConnection $item) => $item->marketplace?->slug === $preferSlug
            );
        }

        $connection ??= $connections->first(
            fn (MarketplaceConnection $item) => $item->marketplace?->slug === 'refurbed'
        ) ?? $connections->first();

        if (! $connection) {
            throw new BusinessRuleException('Connect Refurbed or Back Market before managing inventory.', 422);
        }

        $connector = $this->connectors->connector($connection->marketplace);
        if (
            ! $connector instanceof RefurbedConnector
            && ! $connector instanceof BackMarketConnector
        ) {
            throw new BusinessRuleException('Inventory requires a real marketplace connection.', 422);
        }

        return [$connection, $connector];
    }

    private function persistOffer(MarketplaceConnection $connection, array $offer): MarketplaceOffer
    {
        if (($connection->marketplace?->slug ?? '') === 'back-market') {
            return app(\App\Services\Marketplaces\Sync\BackMarketListingSynchronizer::class)
                ->persist($connection, $offer);
        }

        $externalId = trim((string) ($offer['id'] ?? data_get($offer, 'identifier.id', '')));
        if ($externalId === '') {
            throw new BusinessRuleException('Marketplace returned an offer without an ID.', 502);
        }

        $values = [
            'instance_id' => $offer['instance_id'] ?? data_get($offer, 'instance.id'),
            'instance_name' => $offer['instance_name'] ?? data_get($offer, 'instance.name'),
            'ean' => $offer['ean'] ?? $offer['gtin'] ?? data_get($offer, 'instance.gtin') ?? data_get($offer, 'instance.ean'),
            'sku' => $offer['sku'] ?? data_get($offer, 'identifier.sku'),
            'state' => $offer['state'] ?? null,
            'grading' => $offer['grading'] ?? $offer['offer_grading'] ?? null,
            'warranty' => $offer['warranty'] ?? null,
            'stock' => max(0, (int) ($offer['stock'] ?? 0)),
            'shipping_profile_id' => $offer['shipping_profile_id'] ?? null,
            'reference_currency_code' => $offer['reference_currency_code'] ?? data_get($offer, 'reference_price.currency_code'),
            'reference_price' => is_array($offer['reference_price'] ?? null)
                ? (string) data_get($offer, 'reference_price.amount', 0)
                : (string) ($offer['reference_price'] ?? data_get($offer, 'reference_price.amount', 0)),
            'reference_min_price' => is_array($offer['reference_min_price'] ?? null)
                ? data_get($offer, 'reference_min_price.amount')
                : ($offer['reference_min_price'] ?? data_get($offer, 'reference_min_price.amount')),
            'raw_payload' => $offer,
            'marketplace_created_at' => $offer['created_at'] ?? null,
            'last_synced_at' => now(),
            'pending_upload' => false,
            'is_active' => true,
        ];
        $marketCodes = $this->offerMarketCodes($offer);
        if ($marketCodes !== []) {
            $values['market_codes'] = $marketCodes;
        }

        return MarketplaceOffer::query()->updateOrCreate(
            ['marketplace_connection_id' => $connection->id, 'external_offer_id' => $externalId],
            $values
        );
    }

    private function offerMarkets(MarketplaceConnection $connection): array
    {
        $markets = collect($connection->settings['markets'] ?? [])
            ->filter(fn (mixed $market): bool => is_array($market)
                && ($market['manage_offers'] ?? false)
                && ! empty($market['code']))
            ->map(fn (array $market): array => [
                'code' => strtoupper((string) $market['code']),
                'name' => (string) ($market['name'] ?? $market['code']),
                'currency_code' => $market['currency_code'] ?? null,
            ])
            ->unique('code')
            ->values()
            ->all();

        if ($markets === [] && ($connection->marketplace?->slug ?? '') === 'back-market') {
            $locale = strtolower((string) ($connection->credentials['country_code'] ?? 'fr-fr'));
            $parts = explode('-', $locale);
            $code = strtoupper($parts[1] ?? $parts[0] ?? 'FR');
            $currency = match ($locale) {
                'en-us' => 'USD',
                'ja-jp' => 'JPY',
                'en-gb' => 'GBP',
                default => 'EUR',
            };

            return [[
                'code' => $code,
                'name' => 'Back Market '.$code,
                'currency_code' => $currency,
            ]];
        }

        return $markets;
    }

    private function offerMarketCodes(array $offer): array
    {
        return collect([
            ...($offer['set_market_prices'] ?? []),
            ...($offer['calculated_market_prices'] ?? []),
            ...($offer['market_prices'] ?? []),
            ...(data_get($offer, 'offer.set_market_prices', [])),
            ...(data_get($offer, 'offer.calculated_market_prices', [])),
        ])
            ->filter(fn (mixed $price): bool => is_array($price) && ! empty($price['market_code']))
            ->pluck('market_code')
            ->map(fn (string $code): string => strtoupper($code))
            ->unique()
            ->values()
            ->all();
    }

    private function backfillOfferMarketCodes(MarketplaceConnection $connection): void
    {
        MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->where(function ($query): void {
                $query->whereNull('market_codes')->orWhereJsonLength('market_codes', 0);
            })
            ->get()
            ->each(function (MarketplaceOffer $offer): void {
                $codes = $this->offerMarketCodes($offer->raw_payload ?? []);
                if ($codes !== []) {
                    $offer->forceFill(['market_codes' => $codes])->save();
                }
            });
    }
}
