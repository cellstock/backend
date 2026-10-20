<?php
namespace Tests\Feature\Api;
use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceMerchantAddress};
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\TestCase;

class InventoryListingTest extends TestCase
{
    public function test_listing_persists_details_and_sends_validated_market_prices(): void
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
        Http::preventStrayRequests();
        $market = Marketplace::firstOrCreate(['slug' => 'refurbed'], ['name' => 'Refurbed', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => 2, 'status' => 'active']);
        $connection = MarketplaceConnection::create(['user_id' => $user->id, 'marketplace_id' => $market->id, 'status' => 'connected', 'credentials' => ['api_key' => 'test'], 'settings' => ['markets' => [['code' => 'AT', 'manage_offers' => true]]]]);
        $connection->update(['settings' => ['markets' => [
            ['code' => 'AT', 'manage_offers' => true, 'currency_code' => 'EUR'],
            ['code' => 'CH', 'manage_offers' => true, 'currency_code' => 'CHF'],
        ]]]);
        \App\Models\MarketplaceShippingProfile::create(['marketplace_connection_id' => $connection->id, 'external_id' => 'profile-1', 'name' => 'Warehouse', 'raw_payload' => []]);
        Http::fake(['*/GetInstance' => Http::response(['instance' => ['id' => 'instance-123', 'name' => 'Matched product']])]);
        $this->actingAs($user)->getJson('/api/inventory/instance?identifier=1234567890123&identifier_type=ean_or_sku')
            ->assertOk()->assertJsonPath('data.instance.id', 'instance-123');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '.InstanceService/GetInstance') && $request['gtin'] === '1234567890123');
        $payload = ['gtin' => '1234567890123', 'sku' => 'TEST-1', 'grading' => 'A', 'taxation' => 'GROSS', 'warranty' => 'M12', 'stock' => 3, 'shipping_profile_id' => 'profile-1', 'reference_currency_code' => 'EUR', 'reference_price' => '200.00', 'market_code' => 'AT',
            'listing_details' => ['title' => 'Test product', 'gpsr_name' => 'Manufacturer', 'product_weight' => '0.5'],
            'market_prices' => [['market_code' => 'AT', 'price' => '200.00', 'min_price' => '180.00'], ['market_code' => 'CH', 'price' => '210.00', 'min_price' => '190.00']]];
        Http::fake(['*/CreateOffer' => Http::sequence()->push(['offer' => ['id' => 'remote-1', 'sku' => 'TEST-1', 'reference_price' => '200.00']])->push(['code' => 3, 'message' => 'Invalid product'], 400)]);
        $this->actingAs($user)->postJson('/api/inventory/offers', $payload)->assertStatus(201)->assertJsonPath('data.offer.id', 'remote-1');
        Queue::assertNothingPushed();
        $this->assertFalse(\App\Models\MarketplaceOffer::firstOrFail()->pending_upload);
        $this->postJson('/api/inventory/offers', $payload)->assertStatus(422);
        $this->assertSame(1, \App\Models\MarketplaceOffer::count());
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => $request['create_market_prices'][1]['currency_code'] === 'CHF'
            && $request['create_market_prices'][1]['price'] === '210.00'
            && !isset($request['listing_details']) && !isset($request['market_prices']));
        $saved = \App\Models\MarketplaceOffer::where('external_offer_id', 'remote-1')->firstOrFail();
        $this->assertSame('0.5', $saved->listing_details['product_weight']);
        $this->assertSame(['AT', 'CH'], $saved->market_codes);
        $saved->update(['instance_id' => 'instance-123', 'instance_name' => 'Saved product']);
        $this->getJson('/api/inventory/instance?identifier=TEST-1&identifier_type=ean_or_sku')->assertOk()->assertJsonPath('data.instance.id', 'instance-123');
        $otherUser = User::factory()->create(['role_id' => 2, 'status' => 'active']);
        $otherConnection = MarketplaceConnection::create(['user_id' => $otherUser->id, 'marketplace_id' => $market->id, 'status' => 'connected', 'credentials' => ['api_key' => 'other']]);
        \App\Models\MarketplaceOffer::create(['marketplace_connection_id' => $otherConnection->id, 'external_offer_id' => 'private', 'sku' => 'PRIVATE-SKU', 'instance_id' => 'private-instance']);
        $this->getJson('/api/inventory/instance?identifier=PRIVATE-SKU&identifier_type=ean_or_sku')->assertNotFound();
        $payload['market_prices'][1]['market_code'] = 'US';
        $this->postJson('/api/inventory/offers', $payload)->assertStatus(422);
        $payload['market_prices'][1]['market_code'] = 'CH';
        $payload['market_prices'][1]['min_price'] = '999.00';
        $this->postJson('/api/inventory/offers', $payload)->assertStatus(422);
        $payload['market_prices'][1]['min_price'] = '190.00';
        $payload['shipping_profile_id'] = 'not-owned';
        $this->postJson('/api/inventory/offers', $payload)->assertStatus(422);
        $this->assertSame(0, \App\Models\MarketplaceOperation::count());
        $edit = ['stock' => 4, 'listing_details' => ['title' => 'Updated title'], 'market_prices' => [['market_code' => 'CH', 'price' => '230.00', 'min_price' => '200.00']]];
        Http::fake(['*/UpdateOffer' => Http::response(['offer' => ['id' => 'remote-1', 'sku' => 'TEST-1', 'stock' => 4, 'reference_price' => '200.00', 'set_market_prices' => [['market_code' => 'CH', 'price' => '230.00']]]])]);
        $this->patchJson('/api/inventory/offers/remote-1', $edit)->assertOk();
        $saved->refresh();
        $this->assertSame('Updated title', $saved->listing_details['title']);
        $this->assertSame('Manufacturer', $saved->listing_details['gpsr_name']);
        $this->assertCount(2, json_decode($saved->listing_details['marketplace_prices'], true));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/UpdateOffer') && $request['update_market_prices'][0]['market_code'] === 'CH'
            && $request['update_market_prices'][0]['price'] === '230.00' && !isset($request['listing_details']) && !isset($request['market_prices']));
        $this->getJson('/api/inventory/offers/remote-1')->assertOk()->assertJsonPath('data.offer.listing_details.title', 'Updated title');
        app(\App\Services\Inventory\InventoryService::class)->update($user, 'remote-1', [
            'stock' => 99, 'reference_price' => '999.00',
            'market_prices' => [['market_code' => 'AT', 'price' => '250.00', 'min_price' => '200.00'], ['market_code' => 'CH', 'price' => '260.00']],
            'listing_details' => ['dont_send_quantity_AT' => '1', 'dont_send_price_CH' => '1', 'block_price_AT' => '1'],
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/UpdateOffer')
            && !isset($request['stock'])
            && ($request['reference_price'] ?? null) === '999.00'
            && count($request['update_market_prices'] ?? []) === 1
            && $request['update_market_prices'][0]['min_price'] === '250.00');
    }
}
