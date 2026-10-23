<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\MarketplacePushService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MarketplacePushPreferenceController extends Controller
{
    public function __construct(private readonly MarketplacePushService $pushes) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'marketplaces' => $this->pushes->preferencesFor($request->user()),
                'targets' => $this->pushes->targetsFor($request->user()),
            ],
        );
    }

    public function update(Request $request, string $marketplace): JsonResponse
    {
        $data = $request->validate([
            'slots' => ['required', 'array', 'min:1', 'max:8'],
            'slots.*.key' => ['required', 'string', 'max:32'],
            'slots.*.active' => ['required', 'boolean'],
            'slots.*.start_at' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'slots.*.every' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
        ]);

        try {
            $preference = $this->pushes->updateSlots(
                $request->user(),
                $marketplace,
                $data['slots'],
            );
        } catch (RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            data: ['preference' => $preference],
            message: 'Push preferences saved successfully.',
        );
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targets' => ['sometimes', 'array', 'min:1'],
            'targets.*' => ['required', 'string', 'max:64'],
            'marketplace_slugs' => ['sometimes', 'array', 'min:1'],
            'marketplace_slugs.*' => ['required', 'string', 'in:refurbed,back-market-eu'],
        ]);

        $targets = $data['targets'] ?? $data['marketplace_slugs'] ?? [];
        if ($targets === []) {
            return ApiResponse::error('Select at least one marketplace to push.', 422);
        }

        try {
            $results = $this->pushes->pushNow($request->user(), $targets);
        } catch (RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        $pushed = collect($results)->sum('pushed');

        return ApiResponse::success(
            data: [
                'results' => $results,
                'marketplaces' => $this->pushes->preferencesFor($request->user()),
                'targets' => $this->pushes->targetsFor($request->user()),
            ],
            message: $pushed > 0
                ? "Pushed {$pushed} listing(s) to the selected marketplace(s)."
                : 'Push completed. No pending listings were found for the selected marketplace(s).',
        );
    }
}
