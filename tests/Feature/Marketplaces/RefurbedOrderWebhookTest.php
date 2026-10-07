<?php

namespace Tests\Feature\Marketplaces;

use App\Jobs\ProcessRefurbedOrderNotification;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceWebhookDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\Sync\RefurbedOrderSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefurbedOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_a_notification_once_and_queues_processing(): void
    {
        Queue::fake();
        $connection = $this->connection();
        $url = route('webhooks.refurbed.orders', [$connection, 'webhook-test-secret']);

        $this->postJson($url, ['order_id' => 1234])->assertAccepted();
        $this->postJson($url, ['order_id' => 1234])->assertOk();

        $this->assertDatabaseCount('marketplace_webhook_deliveries', 1);
        Queue::assertPushed(ProcessRefurbedOrderNotification::class, 1);
    }

    public function test_it_rejects_an_invalid_webhook_secret(): void
    {
        Queue::fake();
        $connection = $this->connection();

        $this->postJson(
            route('webhooks.refurbed.orders', [$connection, 'wrong-secret']),
            ['order_id' => 1234]
        )->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_job_fetches_and_idempotently_stores_the_order(): void
    {
        $connection = $this->connection();
        $delivery = MarketplaceWebhookDelivery::query()->create([
            'marketplace_connection_id' => $connection->id,
            'event_type' => 'order.completed',
            'external_id' => '1234',
            'status' => 'received',
            'payload' => ['order_id' => 1234],
            'received_at' => now(),
        ]);
        $order = $this->fixture()['orders'][0];
        $order['id'] = '1234';

        Http::fake([
            '*' => Http::response(['order' => $order]),
        ]);

        $job = new ProcessRefurbedOrderNotification($delivery->id);
        $job->handle(
            app(RefurbedConnector::class),
            app(RefurbedOrderSynchronizer::class)
        );

        $this->assertDatabaseHas('marketplace_orders', [
            'marketplace_connection_id' => $connection->id,
            'external_order_id' => '1234',
        ]);
        $this->assertSame('completed', $delivery->refresh()->status);

        $job->handle(
            app(RefurbedConnector::class),
            app(RefurbedOrderSynchronizer::class)
        );
        $this->assertDatabaseCount('marketplace_orders', 1);
    }

    private function connection(): MarketplaceConnection
    {
        $role = Role::query()->create(['name' => 'Admin', 'slug' => 'admin']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $marketplace = Marketplace::query()->create([
            'name' => 'Refurbed',
            'slug' => 'refurbed',
            'is_active' => true,
        ]);

        return MarketplaceConnection::query()->create([
            'user_id' => $user->id,
            'marketplace_id' => $marketplace->id,
            'credentials' => ['api_key' => 'sandbox-secret-token-value'],
            'settings' => [
                'markets' => [[
                    'code' => 'DE',
                    'name' => 'Germany',
                    'currency_code' => 'EUR',
                    'manage_orders' => true,
                    'manage_offers' => true,
                ]],
            ],
            'webhook_secret' => 'webhook-test-secret',
            'webhook_enabled_at' => now(),
            'status' => 'connected',
        ]);
    }

    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/Marketplaces/refurbed/orders.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
