<?php

namespace App\Jobs;

use App\Models\MarketplaceOperation;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\ShippingProfileService;
use App\Services\Orders\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessMarketplaceOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public readonly int $operationId)
    {
        $this->onQueue('marketplaces');
    }

    public function handle(
        InventoryService $inventory,
        ShippingProfileService $profiles,
        OrderService $orders,
    ): void {
        $operation = MarketplaceOperation::with('user')->findOrFail($this->operationId);
        if ($operation->status === 'completed') {
            return;
        }

        $operation->update([
            'status' => 'processing',
            'attempts' => $operation->attempts + 1,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $user = $operation->user;
        $payload = $operation->payload ?? [];

        $result = match ($operation->type) {
            'offer.create' => $this->createOffer($inventory, $user, $operation, $payload),
            'offer.update' => $inventory->update($user, (string) $operation->entity_id, $payload),
            'offer.delete' => $this->deleteOffer($inventory, $user, (string) $operation->entity_id),
            'shipping_profile.create' => $profiles->create($user, $payload),
            'shipping_profile.update' => $profiles->update($user, (string) $operation->entity_id, $payload),
            'shipping_profile.delete' => $this->deleteProfile($profiles, $user, (string) $operation->entity_id),
            'order.status' => $orders->updateStatus(
                $user,
                (int) $operation->entity_id,
                (string) $payload['status'],
                $payload['tracking_url'] ?? null,
                $payload['item_identifiers'] ?? [],
            )->toArray(),
            'order.refund' => $orders->refund(
                $user,
                (int) $operation->entity_id,
                $payload['item_id'] ?? null,
                $payload['target_paid_amount'] ?? null,
            ),
            'order.shipping_label' => $orders->createShippingLabel($user, (int) $operation->entity_id, $payload),
            'order.shipping_address' => $orders->updateAddress($user, (int) $operation->entity_id, 'shipping', $payload)->toArray(),
            'order.invoice_upload' => $orders->uploadStoredInvoice($user, (int) $operation->entity_id),
            'order.invoice_cache' => ['url' => $orders->cacheInvoiceUrl($user, (int) $operation->entity_id)],
            default => throw new RuntimeException("Unsupported marketplace operation: {$operation->type}"),
        };

        $operation->update([
            'status' => 'completed',
            'result' => is_array($result) ? $result : [],
            'completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceOperation::whereKey($this->operationId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => mb_substr($exception?->getMessage() ?? 'Marketplace operation failed.', 0, 2000),
        ]);
    }

    private function deleteOffer(InventoryService $inventory, mixed $user, string $id): array
    {
        $inventory->delete($user, $id);
        return [];
    }

    private function createOffer(
        InventoryService $inventory,
        mixed $user,
        MarketplaceOperation $operation,
        array $payload,
    ): array {
        $result = $inventory->create($user, $payload);
        if ($operation->entity_id) {
            \App\Models\MarketplaceOffer::where('marketplace_connection_id', $operation->marketplace_connection_id)
                ->where('external_offer_id', $operation->entity_id)
                ->delete();
        }

        return $result;
    }

    private function deleteProfile(ShippingProfileService $profiles, mixed $user, string $id): array
    {
        $profiles->delete($user, $id);
        return [];
    }
}
