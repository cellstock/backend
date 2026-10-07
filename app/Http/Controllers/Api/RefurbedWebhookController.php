<?php

namespace App\Http\Controllers\Api;

use App\Enums\MarketplaceConnectionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessRefurbedOrderNotification;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceWebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefurbedWebhookController extends Controller
{
    public function __invoke(Request $request, MarketplaceConnection $connection, string $token): JsonResponse
    {
        $connection->loadMissing('marketplace');
        if (
            $connection->marketplace?->slug !== 'refurbed'
            || $connection->status !== MarketplaceConnectionStatus::Connected
            || ! filled($connection->webhook_secret)
            || ! hash_equals($connection->webhook_secret, $token)
        ) {
            abort(404);
        }

        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
        ]);
        $orderId = (string) $data['order_id'];

        $delivery = MarketplaceWebhookDelivery::query()->firstOrCreate(
            [
                'marketplace_connection_id' => $connection->id,
                'event_type' => 'order.completed',
                'external_id' => $orderId,
            ],
            [
                'status' => 'received',
                'payload' => ['order_id' => $data['order_id']],
                'received_at' => now(),
            ]
        );

        $connection->forceFill([
            'webhook_activation_status' => 'active',
            'webhook_activated_at' => $connection->webhook_activated_at ?: now(),
            'webhook_activation_error' => null,
            'webhook_last_received_at' => now(),
        ])->save();

        if ($delivery->wasRecentlyCreated || $delivery->status === 'failed') {
            $delivery->forceFill([
                'status' => 'received',
                'error_message' => null,
                'received_at' => now(),
            ])->save();
            ProcessRefurbedOrderNotification::dispatch($delivery->id);
        }

        return response()->json([
            'success' => true,
            'message' => $delivery->wasRecentlyCreated
                ? 'Order notification accepted.'
                : 'Order notification already received.',
        ], $delivery->wasRecentlyCreated ? 202 : 200);
    }
}
