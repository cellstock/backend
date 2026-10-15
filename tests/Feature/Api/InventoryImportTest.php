<?php
namespace Tests\Feature\Api;
use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceMerchantAddress};
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\TestCase;

class InventoryImportTest extends TestCase
{
    public function test_stock_import_returns_a_report_after_queuing_updates(): void
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
        $user = User::factory()->create(['role_id' => 2, 'status' => 'active']);
        $connection = MarketplaceConnection::create(['user_id' => $user->id, 'marketplace_id' => $market->id, 'status' => 'connected', 'credentials' => ['api_key' => 'test'], 'settings' => ['markets' => [['code' => 'AT', 'manage_offers' => true]]]]);
        $offer = \App\Models\MarketplaceOffer::create(['marketplace_connection_id' => $connection->id, 'external_offer_id' => 'offer-1', 'sku' => 'ABC', 'stock' => 1, 'market_codes' => ['AT']]);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('inventory.csv', "sku,stock\nABC,5\n");
        $result = app(\App\Services\Inventory\InventoryService::class)->importStocks($user, $file, 'AT');
        $this->assertSame(1, $result['successful']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(5, $offer->fresh()->stock);
        $this->assertTrue($offer->fresh()->pending_upload);
        Queue::assertPushed(\App\Jobs\ProcessMarketplaceOperation::class);
    }
}
