<?php

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'admin')->firstOrFail()->id,
            'status' => UserStatus::Active,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_empty_dashboard_returns_real_zero_values_and_available_marketplaces(): void
    {
        Marketplace::create(['name' => 'Refurbed', 'slug' => 'refurbed']);
        Sanctum::actingAs($this->user());

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.summary.totalOrders', 0)
            ->assertJsonPath('data.summary.pendingOrders', 0)
            ->assertJsonPath('data.summary.connectedMarketplaces', 0)
            ->assertJsonCount(0, 'data.summary.orderValues')
            ->assertJsonCount(0, 'data.recentOrders')
            ->assertJsonPath('data.marketplaces.0.slug', 'refurbed')
            ->assertJsonPath('data.marketplaces.0.status', 'disconnected');
    }

    public function test_dashboard_scopes_orders_and_connections_and_separates_currencies(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->startOfDay());
        $user = $this->user();
        $marketplace = Marketplace::create(['name' => 'Back Market', 'slug' => 'back-market']);
        $connection = MarketplaceConnection::create([
            'user_id' => $user->id, 'marketplace_id' => $marketplace->id,
            'credentials' => [], 'status' => 'connected',
        ]);
        $other = MarketplaceConnection::create([
            'user_id' => $this->user()->id, 'marketplace_id' => $marketplace->id,
            'credentials' => [], 'status' => 'failed',
        ]);
        foreach ([
            ['old', 'EUR', '100.25', 'PAID', '2026-08-31'],
            ['new', 'EUR', '25.50', 'new', '2026-09-01'],
            ['latest', 'USD', '12.75', 'PENDING', '2026-09-24'],
            ['cancelled', 'EUR', '10.00', 'CANCELLED', '2026-09-02'],
        ] as [$id, $currency, $total, $status, $date]) {
            $connection->orders()->create([
                'external_order_id' => $id, 'currency' => $currency,
                'total_amount' => $total, 'status' => $status, 'ordered_at' => $date,
            ]);
        }
        $other->orders()->create([
            'external_order_id' => 'private', 'currency' => 'GBP',
            'total_amount' => 999, 'status' => 'PENDING', 'ordered_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.summary.totalOrders', 4)
            ->assertJsonPath('data.summary.pendingOrders', 2)
            ->assertJsonPath('data.summary.connectedMarketplaces', 1)
            ->assertJsonPath('data.marketplaces.0.status', 'connected')
            ->assertJsonPath('data.marketplaces.0.ordersThisMonth', 3)
            ->assertJsonPath('data.recentOrders.0.external_order_id', 'latest')
            ->assertJsonPath('data.recentOrders.0.marketplace.slug', 'back-market')
            ->assertJsonCount(2, 'data.summary.orderValues')
            ->assertJsonCount(4, 'data.recentOrders');
        $values = collect($response->json('data.summary.orderValues'))->keyBy('currency');
        $this->assertEquals(135.75, $values['EUR']['total']);
        $this->assertEquals(12.75, $values['USD']['total']);
        $this->assertArrayNotHasKey('shipping_address', $response->json('data.recentOrders.0'));

        $connection->delete();
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.summary.totalOrders', 0)
            ->assertJsonPath('data.summary.connectedMarketplaces', 0)
            ->assertJsonCount(0, 'data.recentOrders');
    }

    public function test_recent_orders_are_limited_and_support_missing_order_dates(): void
    {
        $user = $this->user();
        $marketplace = Marketplace::create(['name' => 'Refurbed', 'slug' => 'refurbed']);
        $connection = MarketplaceConnection::create([
            'user_id' => $user->id, 'marketplace_id' => $marketplace->id,
            'credentials' => [], 'status' => 'pending',
        ]);
        for ($index = 0; $index < 6; $index++) {
            MarketplaceOrder::create([
                'marketplace_connection_id' => $connection->id,
                'external_order_id' => (string) $index,
                'currency' => null, 'total_amount' => 1,
            ]);
        }
        Sanctum::actingAs($user);
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.summary.totalOrders', 6)
            ->assertJsonCount(5, 'data.recentOrders')
            ->assertJsonPath('data.recentOrders.0.external_order_id', '5')
            ->assertJsonPath('data.recentOrders.0.ordered_at', null)
            ->assertJsonPath('data.summary.orderValues.0.currency', null)
            ->assertJsonPath('data.marketplaces.0.ordersThisMonth', 0);
    }
}
