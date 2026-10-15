<?php
namespace Tests\Feature\Api;

use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceOrder};
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class OrderShippingLabelTest extends TestCase
{
    public function test_shipping_label_is_returned_and_saved_without_a_worker(): void
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
        Http::fake(['*' => Http::response(['shipping_label' => [
            'id' => 'label-1',
            'label_printer_url' => 'https://refurbed.test/label.pdf',
            'tracking_data' => ['tracking_number' => 'TRACK-1'],
        ]])]);
        $this->actingAs($user)->postJson('/api/orders/'.$order->id.'/shipping-label', [
            'merchant_address_id' => 'address-1', 'parcel_weight' => 2, 'carrier' => 'dhl-express',
        ])->assertOk()->assertJsonPath('data.shipping_label.id', 'label-1')
            ->assertJsonPath('data.shipping_label.label_printer_url', 'https://refurbed.test/label.pdf');
        $this->assertSame('TRACK-1', $order->fresh()->tracking_number);
        $this->assertDatabaseHas('marketplace_shipping_labels', ['external_id' => 'label-1', 'marketplace_order_id' => $order->id]);
        Queue::assertNothingPushed();
    }
}
