<?php
namespace Tests\Feature\Api;
use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceMerchantAddress};
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\TestCase;

class MerchantAddressSyncTest extends TestCase
{
    public function test_fetch_is_user_scoped_and_preserves_pending_changes(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        config()->set('cache.default', 'array');
        DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (str_contains($path, 'create_refurbed_catalog_tables')) continue;
            (require $path)->up();
        }
        Queue::fake();
        $market = Marketplace::firstOrCreate(['slug' => 'refurbed'], ['name' => 'Refurbed', 'is_active' => true]);
        $owners = [];
        foreach ([1, 2] as $index) {
            $user = User::factory()->create(['role_id' => 2, 'status' => 'active']);
            $connection = MarketplaceConnection::create(['user_id' => $user->id, 'marketplace_id' => $market->id, 'status' => 'connected', 'credentials' => ['api_key' => 'key-'.$index]]);
            $owners[] = [$user, $connection];
        }
        $other = MarketplaceMerchantAddress::create(['marketplace_connection_id' => $owners[1][1]->id, 'external_id' => 'remote-1', 'company_name' => 'Other tenant', 'type' => 'DELIVERY']);
        $pending = MarketplaceMerchantAddress::create(['marketplace_connection_id' => $owners[0][1]->id, 'external_id' => 'pending-edit', 'company_name' => 'Unsaved remote edit', 'type' => 'DELIVERY', 'pending_action' => 'update', 'sync_status' => 'pending']);
        Http::fake(['*' => Http::response(['merchant_addresses' => [
            ['id' => 'remote-1', 'company_name' => 'My warehouse', 'type' => 'DELIVERY'],
            ['id' => 'pending-edit', 'company_name' => 'Old remote value', 'type' => 'DELIVERY'],
        ]])]);
        $this->actingAs($owners[0][0])->postJson('/api/merchant-addresses/sync')->assertOk()->assertJsonCount(2, 'data.addresses');
        $this->assertDatabaseHas('marketplace_merchant_addresses', ['marketplace_connection_id' => $owners[0][1]->id, 'external_id' => 'remote-1', 'company_name' => 'My warehouse']);
        $this->assertSame('Other tenant', $other->fresh()->company_name);
        $this->assertSame('Unsaved remote edit', $pending->fresh()->company_name);
        $this->assertSame('update', $pending->fresh()->pending_action);
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        $saved = MarketplaceMerchantAddress::where('marketplace_connection_id', $owners[0][1]->id)->where('external_id', 'remote-1')->firstOrFail();
        $this->actingAs($owners[0][0])->getJson('/api/merchant-addresses')
            ->assertOk()->assertJsonFragment(['id' => $saved->id, 'external_id' => 'remote-1', 'company_name' => 'My warehouse']);
        Http::assertSentCount(1);
        app(\App\Services\Inventory\MerchantAddressService::class)->list($owners[0][0]);
        app(\App\Services\Inventory\MerchantAddressService::class)->list($owners[0][0]);
        Queue::assertPushed(\App\Jobs\SyncRefurbedReferenceData::class, 1);
        Queue::assertPushed(\App\Jobs\SyncRefurbedReferenceData::class, fn ($job) => $job->connectionId === $owners[0][1]->id);
    }
}
