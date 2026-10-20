<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryService;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MarketplaceOperationService $operations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'search_by' => ['nullable', 'in:all,sku,title,ean'],
            'status' => ['nullable', 'string', 'max:50'],
            'grade' => ['nullable', 'string', 'max:20'],
            'stock' => ['nullable', 'in:in,out'],
            'sort' => ['nullable', 'in:created_desc,created_asc,stock_desc,stock_asc,price_desc,price_asc'],
            'market' => ['nullable', 'string', 'max:10'],
            'marketplace' => ['nullable', 'string', 'in:refurbed,back-market'],
        ]);

        return response()->json(['success' => true, 'data' => $this->inventory->list($request->user(), $data)]);
    }

    public function instance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'identifier_type' => ['required', 'in:gtin,id,mpn,ean_or_sku'],
        ]);

        return response()->json(['success' => true, 'data' => ['instance' => $this->inventory->findInstance($request->user(), $data['identifier'], $data['identifier_type'])]]);
    }

    public function sync(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Inventory synchronized and stored in CelleXa.', 'data' => $this->inventory->synchronize($request->user())]);
    }

    public function diagnostics(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->inventory->diagnostics($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id' => ['required_without_all:gtin,mpn,product_id,backmarket_id', 'nullable', 'string', 'max:64'],
            'gtin' => ['required_without_all:instance_id,mpn,product_id,backmarket_id', 'nullable', 'string', 'max:32'],
            'mpn' => ['required_without_all:instance_id,gtin,product_id,backmarket_id', 'nullable', 'string', 'max:256'],
            'sku' => ['required', 'string', 'max:256'],
            'grading' => ['required', 'in:A,B,C,AA'],
            'taxation' => ['required', 'in:GROSS,MARGINAL'],
            'warranty' => ['required', 'in:M12'],
            'stock' => ['required', 'integer', 'min:0'],
            'shipping_profile_id' => ['required_unless:marketplace,back-market', 'nullable', 'string'],
            'reference_currency_code' => ['required', 'string', 'size:3'],
            'reference_price' => ['required', 'decimal:0,2', 'min:0'],
            'reference_min_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'market_code' => ['required_unless:marketplace,back-market', 'nullable', 'string', 'max:10'],
            'market_prices' => ['sometimes', 'array', 'min:1', 'max:100'],
            'market_prices.*.market_code' => ['required', 'string', 'distinct', 'max:10'],
            'market_prices.*.price' => ['required', 'decimal:0,2', 'min:0'],
            'market_prices.*.min_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'listing_details' => ['sometimes', 'array'],
            'listing_details.*' => ['nullable', 'string', 'max:2000'],
            'marketplace' => ['sometimes', 'nullable', 'string', 'in:refurbed,back-market'],
            'product_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'backmarket_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $marketplaceSlug = $data['marketplace'] ?? 'refurbed';
        $connection = MarketplaceConnection::whereBelongsTo($request->user())
            ->where('status', 'connected')
            ->whereHas('marketplace', fn ($query) => $query->where('slug', $marketplaceSlug))
            ->with('marketplace')
            ->firstOrFail();
        $payload = array_filter($data, fn ($value) => $value !== null && $value !== '');
        $payload['marketplace'] = $marketplaceSlug;

        if ($marketplaceSlug === 'refurbed') {
            $prices = $this->inventory->listingMarketPrices($connection, $payload);
            if (! \App\Models\MarketplaceShippingProfile::where('marketplace_connection_id', $connection->id)->where('external_id', $payload['shipping_profile_id'] ?? '')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['shipping_profile_id' => 'Select a shipping profile belonging to this connection.']);
            }
            $payload['listing_details']['marketplace_prices'] = json_encode($prices);
            $offer = $this->inventory->create($request->user(), $payload);

            return response()->json(['success' => true, 'message' => 'Offer created in Refurbed and saved in CelleXa.', 'data' => ['offer' => $offer]], 201);
        }

        // Back Market create uses CSV upsert; shipping profile is optional.
        if (empty($payload['shipping_profile_id'])) {
            $payload['shipping_profile_id'] = 'n/a';
        }
        $offer = $this->inventory->create($request->user(), $payload);

        return response()->json([
            'success' => true,
            'message' => 'Listing submitted to Back Market and saved in CelleXa.',
            'data' => ['offer' => $offer],
        ], 201);
    }

    public function show(Request $request, string $offerId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['offer' => $this->inventory->getOffer($request->user(), $offerId)],
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'market_code' => ['required', 'string', 'max:10'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inventory file validated. Accepted stock updates are queued for synchronization.',
            'data' => $this->inventory->importStocks($request->user(), $data['file'], $data['market_code']),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer_ids' => ['required', 'array', 'min:1', 'max:100'],
            'offer_ids.*' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:queue_upload,inactive,out_of_stock,add_tag,delete_tag,delete_all_tags'],
            'tag' => ['required_if:action,add_tag,delete_tag', 'nullable', 'string', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Selected inventory offers updated.',
            'data' => $this->inventory->bulk($request->user(), $data),
        ]);
    }

    public function update(Request $request, string $offerId): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['sometimes', 'integer', 'min:0'],
            'reference_price' => ['sometimes', 'decimal:0,2', 'min:0'],
            'reference_min_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'grading' => ['sometimes', 'nullable', 'in:A,B,C,AA'],
            'taxation' => ['sometimes', 'nullable', 'in:GROSS,MARGINAL'],
            'shipping_profile_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'listing_details' => ['sometimes', 'array'],
            // marketplace_prices JSON and long GPSR text can exceed 2000 chars
            'listing_details.*' => ['nullable', 'string', 'max:20000'],
            'market_prices' => ['sometimes', 'array', 'max:100'],
            'market_prices.*.market_code' => ['required', 'string', 'distinct', 'max:10'],
            'market_prices.*.price' => ['required', 'decimal:0,2', 'min:0'],
            'market_prices.*.min_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'market_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        $data = array_filter(
            $data,
            static fn ($value) => $value !== null && $value !== '',
        );

        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Provide at least one offer field to update.'], 422);
        }

        $offer = MarketplaceOffer::whereHas('connection', fn ($query) => $query->whereBelongsTo($request->user()))
            ->where('external_offer_id', $offerId)->firstOrFail();

        if (isset($data['listing_details']) && is_array($data['listing_details'])) {
            $data['listing_details'] = collect($data['listing_details'])
                ->map(fn ($value) => $value === null ? null : (string) $value)
                ->all();
        }

        if (! empty($data['market_prices'])) {
            $prices = $this->inventory->listingMarketPrices($offer->connection, $data + ['reference_currency_code' => $offer->reference_currency_code]);
            foreach ($prices as $price) {
                if (! in_array($price['market_code'], $offer->market_codes ?? [], true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['market_prices' => 'Only existing offer markets can be edited.']);
                }
            }
            $data['listing_details'] = array_replace($offer->listing_details ?? [], $data['listing_details'] ?? [], ['marketplace_prices' => json_encode(array_values(array_replace(collect(json_decode($offer->listing_details['marketplace_prices'] ?? '[]', true) ?: [])->keyBy('market_code')->all(), collect($prices)->keyBy('market_code')->all())))]);
        } elseif (isset($data['listing_details'])) {
            $data['listing_details'] = array_replace($offer->listing_details ?? [], $data['listing_details']);
        }
        if (! empty($data['shipping_profile_id']) && ! \App\Models\MarketplaceShippingProfile::where('marketplace_connection_id', $offer->marketplace_connection_id)->where('external_id', $data['shipping_profile_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['shipping_profile_id' => 'Select a shipping profile belonging to this connection.']);
        }
        $localFields = array_intersect_key($data, array_flip([
            'stock', 'reference_price', 'reference_min_price', 'grading',
            'shipping_profile_id', 'listing_details',
        ]));
        if (isset($data['taxation'])) {
            $localFields['raw_payload'] = array_replace($offer->raw_payload ?? [], [
                'taxation' => $data['taxation'],
                'sync_status' => 'pending',
            ]);
        }
        $localFields['pending_upload'] = true;
        $offer->update($localFields);
        $operation = $this->operations->queue($request->user(), $offer->marketplace_connection_id, 'offer.update', 'offer', $offerId, $data);

        return response()->json([
            'success' => true,
            'message' => 'Offer update queued for Refurbed synchronization.',
            'data' => ['operation_id' => $operation->id],
        ], 202);
    }

    public function destroy(Request $request, string $offerId): JsonResponse
    {
        $offer = MarketplaceOffer::whereHas('connection', fn ($query) => $query->whereBelongsTo($request->user()))
            ->where('external_offer_id', $offerId)->firstOrFail();
        $offer->update(['is_active' => false, 'pending_upload' => true]);
        $operation = $this->operations->queue($request->user(), $offer->marketplace_connection_id, 'offer.delete', 'offer', $offerId);

        return response()->json(['success' => true, 'message' => 'Offer deletion queued for Refurbed synchronization.', 'data' => ['operation_id' => $operation->id]], 202);
    }
}
