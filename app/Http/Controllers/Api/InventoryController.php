<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryService;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\RefurbedCatalogItem;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        ]);

        return response()->json(['success' => true, 'data' => $this->inventory->list($request->user(), $data)]);
    }

    public function instance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'identifier_type' => ['required', 'in:gtin,id,mpn'],
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
            'instance_id' => ['required_without_all:gtin,mpn', 'string'],
            'gtin' => ['required_without_all:instance_id,mpn', 'nullable', 'string'],
            'mpn' => ['required_without_all:instance_id,gtin', 'nullable', 'string'],
            'sku' => ['required', 'string', 'max:256'],
            'grading' => ['required', 'in:A,B,C,AA'],
            'taxation' => ['required', 'in:GROSS,MARGINAL'],
            'warranty' => ['required', 'in:M12'],
            'stock' => ['required', 'integer', 'min:0'],
            'shipping_profile_id' => ['required', 'string'],
            'reference_currency_code' => ['required', 'string', 'size:3'],
            'reference_price' => ['required', 'decimal:0,2', 'min:0'],
            'reference_min_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'market_code' => ['required', 'string', 'max:10'],
            'listing_details' => ['sometimes', 'array'],
            'listing_details.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $connection = MarketplaceConnection::whereBelongsTo($request->user())
            ->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))
            ->firstOrFail();
        $payload = array_filter($data, fn ($value) => $value !== null && $value !== '');
        $temporaryId = 'pending-'.Str::uuid();
        $catalogItem = isset($payload['instance_id'])
            ? RefurbedCatalogItem::where('instance_id', $payload['instance_id'])->latest('catalog_import_id')->first()
            : null;
        MarketplaceOffer::create([
            'marketplace_connection_id' => $connection->id,
            'external_offer_id' => $temporaryId,
            'instance_id' => $payload['instance_id'] ?? null,
            'instance_name' => $catalogItem?->name,
            'ean' => $payload['gtin'] ?? null,
            'sku' => $payload['sku'],
            'grading' => $payload['grading'],
            'warranty' => $payload['warranty'],
            'stock' => $payload['stock'],
            'shipping_profile_id' => $payload['shipping_profile_id'],
            'reference_currency_code' => $payload['reference_currency_code'],
            'reference_price' => $payload['reference_price'],
            'reference_min_price' => $payload['reference_min_price'] ?? null,
            'market_codes' => [$payload['market_code']],
            'raw_payload' => ['taxation' => $payload['taxation'], 'sync_status' => 'pending'],
            'listing_details' => $payload['listing_details'] ?? [],
            'pending_upload' => true,
            'is_active' => true,
        ]);
        $operation = $this->operations->queue($request->user(), $connection->id, 'offer.create', 'offer', $temporaryId, $payload);

        return response()->json(['success' => true, 'message' => 'Offer queued for Refurbed synchronization.', 'data' => ['operation_id' => $operation->id]], 202);
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
            'grading' => ['sometimes', 'in:A,B,C,AA'],
            'taxation' => ['sometimes', 'in:GROSS,MARGINAL'],
            'shipping_profile_id' => ['sometimes', 'string'],
            'listing_details' => ['sometimes', 'array'],
            'listing_details.*' => ['nullable', 'string', 'max:2000'],
            'market_code' => ['sometimes', 'string', 'max:10'],
        ]);

        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Provide at least one offer field to update.'], 422);
        }

        $offer = MarketplaceOffer::whereHas('connection', fn ($query) => $query->whereBelongsTo($request->user()))
            ->where('external_offer_id', $offerId)->firstOrFail();
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
