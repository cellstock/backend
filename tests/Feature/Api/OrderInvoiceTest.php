<?php
namespace Tests\Feature\Api;

use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceOrder};
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class OrderInvoiceTest extends TestCase
{
    public function test_invoice_is_fetched_without_a_worker_and_missing_invoice_returns_an_error(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (str_contains($path, 'create_refurbed_catalog_tables')) continue;
            (require $path)->up();
        }
        Queue::fake();
        config()->set('services.refurbed.base_url', 'https://refurbed.test');
        $user = User::factory()->create(['role_id' => 2, 'status' => 'active']);
        $marketplace = Marketplace::firstOrCreate(['slug' => 'refurbed'], ['name' => 'Refurbed', 'is_active' => true]);
        $connection = MarketplaceConnection::create([
            'user_id' => $user->id, 'marketplace_id' => $marketplace->id,
            'status' => 'connected', 'credentials' => ['api_key' => 'test-invoice-key'],
            'settings' => ['markets' => [['code' => 'DE', 'manage_orders' => true]]],
        ]);
        $order = MarketplaceOrder::create([
            'marketplace_connection_id' => $connection->id, 'external_order_id' => 'test-1', 'country_code' => 'DE',
        ]);
        Http::fake(['*' => Http::sequence()->push(['url' => 'https://refurbed.test/invoice.pdf'])->push(['code' => 5, 'message' => 'invoice not found'], 404)]);
        $this->actingAs($user)->getJson('/api/orders/'.$order->id.'/invoice')
            ->assertOk()->assertJsonPath('data.url', 'https://refurbed.test/invoice.pdf');
        $this->assertSame('https://refurbed.test/invoice.pdf', $order->fresh()->invoice_url);
        Queue::assertNothingPushed();
        $order->update(['invoice_url' => null]);
        $this->getJson('/api/orders/'.$order->id.'/invoice')
            ->assertNotFound()->assertJsonPath('success', false);
        Queue::assertNothingPushed();
    }
}
