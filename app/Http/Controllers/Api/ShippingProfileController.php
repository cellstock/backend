<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\ShippingProfileService;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceShippingProfile;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingProfileController extends Controller
{
    public function __construct(private readonly ShippingProfileService $profiles, private readonly MarketplaceOperationService $operations) {}
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'source_country' => ['nullable', 'string', 'max:10'],
            'deletable' => ['nullable', 'in:yes,no'],
            'sort' => ['nullable', 'in:name,newest,offers'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->profiles->list($request->user(), $filters),
        ]);
    }
    public function sync(Request $request): JsonResponse { return response()->json(['success' => true, 'message' => 'Shipping profiles synchronized.', 'data' => $this->profiles->sync($request->user())]); }
    public function carriers(Request $request): JsonResponse { return response()->json(['success' => true, 'data' => ['carriers' => $this->profiles->carriers($request->user())]]); }
    public function store(Request $request): JsonResponse { $data = $request->validate(['profile' => ['required', 'array']]); $connection = $this->connection($request); $operation = $this->operations->queue($request->user(), $connection->id, 'shipping_profile.create', 'shipping_profile', null, $data['profile']); return response()->json(['success' => true, 'message' => 'Shipping profile creation queued.', 'data' => ['operation_id' => $operation->id]], 202); }
    public function update(Request $request, string $profileId): JsonResponse { $data = $request->validate(['profile' => ['required', 'array']]); $profile = $this->profile($request, $profileId); $operation = $this->operations->queue($request->user(), $profile->marketplace_connection_id, 'shipping_profile.update', 'shipping_profile', $profileId, $data['profile']); return response()->json(['success' => true, 'message' => 'Shipping profile update queued.', 'data' => ['operation_id' => $operation->id]], 202); }
    public function destroy(Request $request, string $profileId): JsonResponse { $profile = $this->profile($request, $profileId); $operation = $this->operations->queue($request->user(), $profile->marketplace_connection_id, 'shipping_profile.delete', 'shipping_profile', $profileId); return response()->json(['success' => true, 'message' => 'Shipping profile deletion queued.', 'data' => ['operation_id' => $operation->id]], 202); }

    private function connection(Request $request): MarketplaceConnection { return MarketplaceConnection::whereBelongsTo($request->user())->whereHas('marketplace', fn ($query) => $query->where('slug', 'refurbed'))->firstOrFail(); }
    private function profile(Request $request, string $id): MarketplaceShippingProfile { return MarketplaceShippingProfile::whereHas('connection', fn ($query) => $query->whereBelongsTo($request->user()))->where('external_id', $id)->firstOrFail(); }
}
