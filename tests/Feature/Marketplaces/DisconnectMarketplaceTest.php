<?php

namespace Tests\Feature\Marketplaces;

use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketplaces\MarketplaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DisconnectMarketplaceTest extends TestCase
{
    public function test_disconnect_deletes_only_the_target_connections_local_data(): void
    {
        // Use the actual application foreign keys, excluding the independent
        // shared catalog whose full-text index is not supported by SQLite.
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (str_contains($path, 'create_refurbed_catalog_tables')) continue;
            (require $path)->up();
        }
        Http::fake();
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $otherUser = User::factory()->create(['role_id' => $role->id]);
        $marketplace = Marketplace::firstOrCreate(['slug' => 'refurbed'], ['name' => 'Refurbed', 'is_active' => true]);
        $otherMarketplace = Marketplace::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $connections = [];
        foreach ([[$user, $marketplace], [$otherUser, $marketplace], [$user, $otherMarketplace]] as [$owner, $market]) {
            $connection = MarketplaceConnection::create([
                'user_id' => $owner->id, 'marketplace_id' => $market->id,
                'credentials' => ['api_key' => 'test-key'], 'status' => 'connected',
            ]);
            $connections[] = $connection;
            DB::table('marketplace_orders')->insert([
                'marketplace_connection_id' => $connection->id, 'external_order_id' => 'order-1',
            ]);
            DB::table('marketplace_sync_runs')->insert([
                'marketplace_connection_id' => $connection->id, 'user_id' => $owner->id, 'type' => 'orders',
            ]);
            DB::table('marketplace_operations')->insert([
                'marketplace_connection_id' => $connection->id, 'user_id' => $owner->id, 'type' => 'offer.delete',
            ]);
        }
        app(MarketplaceService::class)->disconnect($user, $marketplace);
        $this->assertDatabaseMissing('marketplace_connections', ['id' => $connections[0]->id]);
        foreach (['marketplace_orders', 'marketplace_sync_runs', 'marketplace_operations'] as $table) {
            $this->assertDatabaseMissing($table, ['marketplace_connection_id' => $connections[0]->id]);
            foreach (array_slice($connections, 1) as $connection) {
                $this->assertDatabaseHas($table, ['marketplace_connection_id' => $connection->id]);
            }
            $this->assertDatabaseCount($table, 2);
        }
        $this->assertDatabaseCount('marketplace_connections', 2);
        $this->assertDatabaseHas('marketplaces', ['id' => $marketplace->id]);
        $this->assertDatabaseHas('marketplaces', ['id' => $otherMarketplace->id]);
        Http::assertNothingSent();
    }
}
