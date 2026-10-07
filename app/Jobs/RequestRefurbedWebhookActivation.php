<?php

namespace App\Jobs;

use App\Models\MarketplaceConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RequestRefurbedWebhookActivation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $connectionId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(): void
    {
        $connection = MarketplaceConnection::query()
            ->with('marketplace')
            ->findOrFail($this->connectionId);

        if (
            $connection->marketplace?->slug !== 'refurbed'
            || ! $this->managesOrders($connection)
            || ! filled($connection->webhook_secret)
        ) {
            return;
        }

        $notificationPath = route('webhooks.refurbed.orders', [
            'connection' => $connection->id,
            'token' => $connection->webhook_secret,
        ], false);
        $notificationUrl = rtrim(config('services.refurbed.webhook_base_url'), '/')
            .'/'.ltrim($notificationPath, '/');

        Mail::raw(
            implode("\n", [
                'Hello Refurbed Integrations team,',
                '',
                'Please enable Instant Order Notifications for this merchant account:',
                'Account name: '.($connection->account_name ?: 'Not supplied'),
                'External account ID: '.($connection->external_account_id ?: 'Not supplied'),
                'Notification handler URL: '.$notificationUrl,
                '',
                'This URL is managed automatically by CelleXa.',
            ]),
            function ($message) use ($connection) {
                $message
                    ->to(config('services.refurbed.integrations_email'))
                    ->subject(
                        'Enable Instant Order Notifications for '
                        .($connection->account_name ?: 'CelleXa merchant '.$connection->id)
                    );
            }
        );

        Log::info('Refurbed webhook activation email sent.', [
            'marketplace_connection_id' => $connection->id,
            'account_name' => $connection->account_name,
            'recipient' => config('services.refurbed.integrations_email'),
            'mailer' => config('mail.default'),
        ]);

        $connection->forceFill([
            'webhook_activation_status' => 'requested',
            'webhook_activation_requested_at' => now(),
            'webhook_activation_error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceConnection::query()
            ->whereKey($this->connectionId)
            ->update([
                'webhook_activation_status' => 'failed',
                'webhook_activation_error' => $exception?->getMessage()
                    ?? 'The activation request could not be sent.',
            ]);
    }

    private function managesOrders(MarketplaceConnection $connection): bool
    {
        return collect($connection->settings['markets'] ?? [])
            ->contains(fn (array $market) => (bool) ($market['manage_orders'] ?? false));
    }
}
