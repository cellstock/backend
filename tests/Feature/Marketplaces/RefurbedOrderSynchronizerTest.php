<?php

namespace Tests\Feature\Marketplaces;

use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\Role;
use App\Models\User;
use App\Http\Resources\OrderResource;
use App\Services\Marketplaces\Sync\RefurbedOrderSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RefurbedOrderSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_idempotently_persists_orders_and_items(): void
    {
        $connection = $this->connection();
        $source = $this->fixture()['orders'][0];
        $synchronizer = app(RefurbedOrderSynchronizer::class);

        $firstOrder = $synchronizer->synchronize(
            $connection,
            $source
        );

        $this->assertSame(
            'test-order-1001',
            $firstOrder->external_order_id
        );
        $this->assertSame('359.99', $firstOrder->total_amount);
        $this->assertSame('DE', $firstOrder->country_code);
        $this->assertCount(1, $firstOrder->items);
        $this->assertSame(
            'TEST-SKU-001',
            $firstOrder->items->first()->sku
        );

        $resource = (new OrderResource(
            $firstOrder->load('connection.marketplace', 'items')
        ))->toArray(Request::create('/api/orders'));

        $this->assertSame('Test Customer', $resource['customer']['name']);
        $this->assertSame(
            'customer@example.com',
            $resource['customer']['email']
        );
        $this->assertTrue($resource['payment']['is_paid']);
        $this->assertSame('Berlin', $resource['shipping_address']['city']);
        $this->assertCount(1, $resource['items']);

        $source['state'] = 'PROCESSING';
        $source['items'][0]['sku'] = 'UPDATED-SKU';

        $updatedOrder = $synchronizer->synchronize(
            $connection,
            $source
        );

        $this->assertSame($firstOrder->id, $updatedOrder->id);
        $this->assertSame('PROCESSING', $updatedOrder->status);
        $this->assertSame(
            'UPDATED-SKU',
            $updatedOrder->items->first()->sku
        );
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertDatabaseCount('marketplace_order_items', 1);

        $source['items'] = [];
        $synchronizer->synchronize($connection, $source);

        $this->assertDatabaseCount('marketplace_order_items', 0);
    }

    public function test_it_reduces_offer_stock_once_for_a_new_webhook_order(): void
    {
        $connection = $this->connection();
        $source = $this->fixture()['orders'][0];

        $offer = MarketplaceOffer::query()->create([
            'marketplace_connection_id' => $connection->id,
            'external_offer_id' => 'offer-1',
            'sku' => 'TEST-SKU-001',
            'stock' => 5,
            'reference_price' => 359.99,
        ]);

        $synchronizer = app(RefurbedOrderSynchronizer::class);
        $synchronizer->synchronize($connection, $source, adjustStock: true);

        $this->assertSame(4, $offer->refresh()->stock);

        $synchronizer->synchronize($connection, $source, adjustStock: true);

        $this->assertSame(4, $offer->refresh()->stock);
    }

    public function test_it_restores_offer_stock_once_when_an_order_is_cancelled(): void
    {
        $connection = $this->connection();
        $source = $this->fixture()['orders'][0];
        $offer = MarketplaceOffer::query()->create([
            'marketplace_connection_id' => $connection->id,
            'external_offer_id' => 'offer-1',
            'sku' => 'TEST-SKU-001',
            'stock' => 5,
            'reference_price' => 359.99,
        ]);
        $synchronizer = app(RefurbedOrderSynchronizer::class);
        $synchronizer->synchronize($connection, $source, adjustStock: true);

        $source['state'] = 'CANCELLED';
        $synchronizer->synchronize($connection, $source, adjustStock: true);
        $this->assertSame(5, $offer->refresh()->stock);

        $synchronizer->synchronize($connection, $source, adjustStock: true);
        $this->assertSame(5, $offer->refresh()->stock);
    }

    private function connection(): MarketplaceConnection
    {
        $role = Role::query()->create([
            'name' => 'User',
            'slug' => 'user',
        ]);

        $user = User::factory()->create([
            'role_id' => $role->id,
        ]);

        $marketplace = Marketplace::query()->create([
            'name' => 'Refurbed',
            'slug' => 'refurbed',
            'is_active' => true,
        ]);

        return MarketplaceConnection::query()->create([
            'user_id' => $user->id,
            'marketplace_id' => $marketplace->id,
            'credentials' => [
                'api_key' => 'sandbox-secret-token-value',
            ],
            'settings' => [],
            'status' => 'connected',
        ]);
    }

    private function fixture(): array
    {
        $contents = file_get_contents(
            base_path(
                'tests/Fixtures/Marketplaces/refurbed/orders.json'
            )
        );

        $this->assertNotFalse($contents);

        return json_decode(
            $contents,
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
