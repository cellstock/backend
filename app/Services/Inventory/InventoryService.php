<?php

namespace App\Services\Inventory;

use App\Enums\MarketplaceConnectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\RefurbedCatalogItem;
use App\Models\User;
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
        [$connection] = $this->refurbed($user);
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
        $this->refurbed($user);
        $item = RefurbedCatalogItem::query()
            ->when(
                $identifierType === 'id',
                fn ($query) => $query->where('instance_id', $identifier),
                fn ($query) => $query->where('name', 'like', "%{$identifier}%")
            )
            ->latest('catalog_import_id')
            ->first();

        if (! $item) {
            throw new BusinessRuleException('No matching instance exists in the imported Refurbed catalog.', 404);
        }

        return [
            'id' => $item->instance_id,
            'name' => $item->name,
            'name_de' => $item->name_de,
            'attributes' => $item->attributes,
            'attributes_de' => $item->attributes_de,
            'category' => $item->main_category,
        ];
    }

    public function create(User $user, array $data): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $listingDetails = $data['listing_details'] ?? null;
        $marketCode = strtoupper((string) ($data['market_code'] ?? ''));
        $market = collect($this->offerMarkets($connection))->firstWhere('code', $marketCode);

        if (! $market) {
            throw new BusinessRuleException('This market is not enabled for offer management.', 422);
        }

        $data['create_market_prices'] = [[
            'market_code' => $marketCode,
            'currency_code' => $market['currency_code'] ?? $data['reference_currency_code'],
            'price' => (string) $data['reference_price'],
            'min_price' => (string) ($data['reference_min_price'] ?? $data['reference_price']),
        ]];
        unset($data['listing_details'], $data['market_code']);
        $offer = $connector->createOffer($connection, $data);
        $stored = $this->persistOffer($connection, $offer);
        if ($stored->market_codes === []) {
            $stored->market_codes = [$marketCode];
        }
        if ($listingDetails !== null) {
            $stored->listing_details = $listingDetails;
        }
        $stored->save();

        return $offer;
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
        unset($data['listing_details'], $data['market_code']);

        if ($marketCode !== '') {
            $market = collect($this->offerMarkets($connection))->firstWhere('code', $marketCode);
            if (! $market || ! in_array($marketCode, $stored->market_codes ?? [], true)) {
                throw new BusinessRuleException('This offer is not available in the selected market.', 422);
            }

            $data['update_market_prices'] = [[
                'market_code' => $marketCode,
                'currency_code' => $market['currency_code'] ?? $stored->reference_currency_code,
                'price' => (string) ($data['reference_price'] ?? $stored->reference_price),
                'min_price' => (string) ($data['reference_min_price'] ?? $stored->reference_min_price ?? $stored->reference_price),
            ]];
        }

        $remoteOffer = null;
        if ($data !== []) {
            $remoteOffer = $connector->updateOffer($connection, $offerId, $data);
            $stored = $this->persistOffer($connection, $remoteOffer);
        }
        if ($listingDetails !== null) {
            $stored->forceFill(['listing_details' => $listingDetails])->save();
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
            'successful' => count($stocks) - count($remoteFailures),
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }

    private function importFailure(?int $row, string $sku, string $stock, string $message, int $code = 422): array
    {
        return compact('row', 'sku', 'stock', 'code', 'message');
    }

    public function synchronize(User $user): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $cursor = null;
        $seen = [];
        $count = 0;

        do {
            $result = $connector->listOffers($connection, $cursor, 100);
            foreach ($result['offers'] as $offer) {
                if (! is_array($offer)) {
                    continue;
                }
                $stored = $this->persistOffer($connection, $offer);
                $seen[] = $stored->external_offer_id;
                $count++;
            }
            $cursor = $result['next_cursor'] ?: null;
        } while ($result['has_more'] && $cursor !== null && $count < 10000);

        if (! $result['has_more']) {
            MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->when($seen !== [], fn ($query) => $query->whereNotIn('external_offer_id', $seen))
                ->when($seen === [], fn ($query) => $query)
                ->delete();
        }

        $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return ['synchronized' => $count, 'complete' => ! $result['has_more']];
    }

    private function refurbed(User $user): array
    {
        $connection = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))
            ->with('marketplace')
            ->first();

        if (! $connection) {
            throw new BusinessRuleException('Connect Refurbed before managing inventory.', 422);
        }

        $connector = $this->connectors->connector($connection->marketplace);
        if (! $connector instanceof RefurbedConnector) {
            throw new BusinessRuleException('Inventory requires the real Refurbed connection.', 422);
        }

        return [$connection, $connector];
    }

    private function persistOffer(MarketplaceConnection $connection, array $offer): MarketplaceOffer
    {
        $externalId = trim((string) ($offer['id'] ?? data_get($offer, 'identifier.id', '')));
        if ($externalId === '') {
            throw new BusinessRuleException('Refurbed returned an offer without an ID.', 502);
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
            'reference_price' => (string) ($offer['reference_price'] ?? data_get($offer, 'reference_price.amount', 0)),
            'reference_min_price' => $offer['reference_min_price'] ?? data_get($offer, 'reference_min_price.amount'),
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
        return collect($connection->settings['markets'] ?? [])
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
