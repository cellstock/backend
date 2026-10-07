<?php

namespace App\Jobs;

use App\Enums\MarketplaceConnectionStatus;
use App\Models\MarketplaceWebhookDelivery;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\Sync\RefurbedOrderSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessRefurbedOrderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('marketplaces');
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(
        RefurbedConnector $connector,
        RefurbedOrderSynchronizer $synchronizer
    ): void {
        $delivery = MarketplaceWebhookDelivery::query()
            ->with('connection.marketplace')
            ->findOrFail($this->deliveryId);

        if (in_array($delivery->status, ['completed', 'ignored'], true)) {
            return;
        }

        $connection = $delivery->connection;
        if (
            ! $connection
            || $connection->status !== MarketplaceConnectionStatus::Connected
            || $connection->marketplace?->slug !== 'refurbed'
        ) {
            throw new RuntimeException('The Refurbed connection is not active.');
        }

        $delivery->forceFill([
            'status' => 'processing',
            'attempts' => $delivery->attempts + 1,
            'error_message' => null,
        ])->save();

        $order = $connector->getOrder($connection, $delivery->external_id);
        if (! $this->managesOrderMarket($connection->settings ?? [], $order)) {
            $delivery->forceFill([
                'status' => 'ignored',
                'processed_at' => now(),
                'error_message' => 'The order market is not enabled for order management.',
            ])->save();

            return;
        }

        $synchronizer->synchronize($connection, $order, adjustStock: true);
        $delivery->forceFill([
            'status' => 'completed',
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceWebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => mb_substr($exception?->getMessage() ?? 'Order notification processing failed.', 0, 2000),
            ]);
    }

    private function managesOrderMarket(array $settings, array $order): bool
    {
        $markets = $settings['markets'] ?? null;
        if (! is_array($markets)) {
            return true;
        }

        $countryCode = strtoupper((string) data_get($order, 'shipping_address.country_code', ''));

        return collect($markets)->contains(fn (mixed $market): bool => is_array($market)
            && ($market['manage_orders'] ?? false)
            && strtoupper((string) ($market['code'] ?? '')) === $countryCode);
    }
}
