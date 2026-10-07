<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceResource;
use App\Models\Marketplace;
use App\Services\Marketplaces\MarketplaceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Marketplace\ConnectMarketplaceRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceConnectionRequest;
use Illuminate\Http\Request;
use App\Enums\MarketplaceSyncType;
use App\Http\Requests\Marketplace\SyncMarketplaceRequest;
use App\Http\Resources\MarketplaceSyncRunResource;
use App\Models\MarketplaceSyncRun;
use App\Http\Requests\Marketplace\MarketplaceConnectionOptionsRequest;


class MarketplaceController extends Controller
{
    public function __construct(
        private readonly MarketplaceService $marketplaceService
    ) {
    }

    public function index(
        Request $request
    ): JsonResponse {
        $marketplaces = $this->marketplaceService->list(
            $request->user()
        );

        return ApiResponse::success(
            data: [
                'connected' => MarketplaceResource::collection(
                    $marketplaces['connected']
                ),
                'available' => MarketplaceResource::collection(
                    $marketplaces['available']
                ),
            ],
            message: 'Marketplaces retrieved successfully.',
        );
    }

    public function show(
        Request $request,
        Marketplace $marketplace
    ): JsonResponse {
        $marketplace = $this->marketplaceService->get(
            user: $request->user(),
            marketplace: $marketplace,
        );

        return ApiResponse::success(
            data: [
                'marketplace' =>
                    new MarketplaceResource($marketplace),
            ],
            message: 'Marketplace retrieved successfully.',
        );
    }

    public function connect(
        ConnectMarketplaceRequest $request,
        Marketplace $marketplace
    ): JsonResponse {
        $marketplace = $this->marketplaceService->connect(
            user: $request->user(),
            marketplace: $marketplace,
            data: $request->validated(),
        );

        return ApiResponse::success(
            data: [
                'marketplace' =>
                    new MarketplaceResource($marketplace),
            ],
            message: 'Marketplace connected successfully.',
            status: 201,
        );
    }

    public function connectionOptions(
        MarketplaceConnectionOptionsRequest $request,
        Marketplace $marketplace
    ): JsonResponse {
        return ApiResponse::success(
            data: $this->marketplaceService->connectionOptions(
                $request->user(),
                $marketplace,
                $request->validated('credentials', [])
            ),
            message: 'Marketplace connection options retrieved successfully.',
        );
    }

    public function updateConnection(
        UpdateMarketplaceConnectionRequest $request,
        Marketplace $marketplace
    ): JsonResponse {
        $marketplace =
            $this->marketplaceService->updateConnection(
                user: $request->user(),
                marketplace: $marketplace,
                data: $request->validated(),
            );

        return ApiResponse::success(
            data: [
                'marketplace' =>
                    new MarketplaceResource($marketplace),
            ],
            message: 'Marketplace connection updated successfully.',
        );
    }

    public function testConnection(
        Request $request,
        Marketplace $marketplace
    ): JsonResponse {
        $marketplace =
            $this->marketplaceService->testConnection(
                user: $request->user(),
                marketplace: $marketplace,
            );

        return ApiResponse::success(
            data: [
                'marketplace' =>
                    new MarketplaceResource($marketplace),
            ],
            message: 'Marketplace connection tested successfully.',
        );
    }

    public function disconnect(
        Request $request,
        Marketplace $marketplace
    ): JsonResponse {
        $this->marketplaceService->disconnect(
            user: $request->user(),
            marketplace: $marketplace,
        );

        return ApiResponse::success(
            data: null,
            message: 'Marketplace disconnected successfully.',
        );
    }

    public function sync(
        SyncMarketplaceRequest $request,
        Marketplace $marketplace
    ): JsonResponse {
        $syncRun = $this->marketplaceService->sync(
            user: $request->user(),
            marketplace: $marketplace,
            type: MarketplaceSyncType::from(
                $request->validated('type')
            ),
        );

        return ApiResponse::success(
            data: [
                'sync_run' =>
                    new MarketplaceSyncRunResource($syncRun),
            ],
            message: 'Marketplace synchronization queued successfully.',
            status: 202,
        );
    }

    public function syncRun(
        Request $request,
        Marketplace $marketplace,
        MarketplaceSyncRun $syncRun
    ): JsonResponse {
        $syncRun = $this->marketplaceService->syncRun(
            user: $request->user(),
            marketplace: $marketplace,
            syncRun: $syncRun,
        );

        return ApiResponse::success(
            data: ['sync_run' => new MarketplaceSyncRunResource($syncRun)],
            message: 'Marketplace synchronization status retrieved successfully.',
        );
    }
}
