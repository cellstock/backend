<?php

namespace App\Services\Marketplaces;

use App\Enums\MarketplaceConnectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Jobs\RequestRefurbedWebhookActivation;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\User;
use Illuminate\Support\Str;

class RefurbedWebhookService
{
    public function details(User $user, Marketplace $marketplace): array
    {
        $connection = $this->connection($user, $marketplace);

        return $this->present($connection);
    }

    public function generate(User $user, Marketplace $marketplace): array
    {
        $connection = $this->connection($user, $marketplace);

        if (! filled($connection->webhook_secret)) {
            $connection->forceFill([
                'webhook_secret' => Str::random(64),
                'webhook_enabled_at' => now(),
                'webhook_activation_status' => 'generated',
                'webhook_activation_error' => null,
            ])->save();
        }

        return $this->present($connection->refresh());
    }

    public function requestActivation(User $user, Marketplace $marketplace): array
    {
        $connection = $this->connection($user, $marketplace);

        if (! $this->managesOrders($connection)) {
            throw new BusinessRuleException(
                'Enable Manage orders for at least one marketplace locale first.',
                422,
            );
        }

        if (! filled($connection->webhook_secret)) {
            throw new BusinessRuleException('Generate the notification URL first.', 422);
        }

        $this->ensureActivationRequested($connection);

        return $this->present($connection->refresh());
    }

    public function ensureActivationRequested(MarketplaceConnection $connection): bool
    {
        $connection->loadMissing('marketplace');

        if (
            $connection->marketplace?->slug !== 'refurbed'
            || $connection->status !== MarketplaceConnectionStatus::Connected
            || ! $this->managesOrders($connection)
            || ! filled($connection->webhook_secret)
            || in_array($connection->webhook_activation_status, ['pending', 'requested', 'active'], true)
        ) {
            return false;
        }

        $connection->forceFill([
            'webhook_activation_status' => 'pending',
            'webhook_activation_error' => null,
        ])->save();

        RequestRefurbedWebhookActivation::dispatch($connection->id)->afterCommit();

        return true;
    }

    private function connection(User $user, Marketplace $marketplace): MarketplaceConnection
    {
        if ($marketplace->slug !== 'refurbed') {
            throw new BusinessRuleException('Instant order notifications are available only for Refurbed.', 422);
        }

        $connection = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($marketplace)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->first();

        if (! $connection) {
            throw new BusinessRuleException('Connect Refurbed before configuring order notifications.', 422);
        }

        return $connection;
    }

    private function present(MarketplaceConnection $connection): array
    {
        $status = $connection->webhook_activation_status ?? 'not_requested';

        if ($status === 'not_requested' && filled($connection->webhook_secret)) {
            $status = 'generated';
        }

        return [
            'status' => $status,
            'manages_orders' => $this->managesOrders($connection),
            'notification_url' => filled($connection->webhook_secret)
                ? $this->notificationUrl($connection)
                : null,
            'requested_at' => $connection->webhook_activation_requested_at?->toISOString(),
            'activated_at' => $connection->webhook_activated_at?->toISOString(),
            'last_received_at' => $connection->webhook_last_received_at?->toISOString(),
        ];
    }

    private function managesOrders(MarketplaceConnection $connection): bool
    {
        return collect($connection->settings['markets'] ?? [])
            ->contains(fn (array $market) => (bool) ($market['manage_orders'] ?? false));
    }

    private function notificationUrl(MarketplaceConnection $connection): string
    {
        $path = route('webhooks.refurbed.orders', [
            'connection' => $connection->id,
            'token' => $connection->webhook_secret,
        ], false);

        return rtrim(config('services.refurbed.webhook_base_url'), '/')
            .'/'.ltrim($path, '/');
    }
}
