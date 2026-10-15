<?php
namespace Tests\Feature\Api;

use App\Models\{User, Marketplace, MarketplaceConnection, MarketplaceOrder};
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class OrderInvoiceUploadTest extends TestCase
{
    public function test_invoice_upload_sends_pdf_without_a_worker_and_reports_rejection(): void
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
        \Illuminate\Support\Facades\Storage::fake('local');
        $order->update(['raw_payload' => ['is_invoiceable' => false]]);
        Http::fake(['*' => Http::sequence()->push([])->push(['code' => 3, 'message' => 'Order is not invoiceable'], 400)]);
        $file = \Illuminate\Http\UploadedFile::fake()->create('bill.pdf', 1, 'application/pdf');
        $this->actingAs($user)->postJson('/api/orders/'.$order->id.'/invoice', ['file' => $file])
            ->assertOk()->assertJsonPath('data.refurbed_uploaded', true);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($order->fresh()->custom_invoice_path);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/SetOrderInvoiceStream')
            && $request[0]['meta']['order_id'] === 'test-1'
            && $request[1]['data'] === base64_encode(file_get_contents($file->getRealPath())));
        $this->postJson('/api/orders/'.$order->id.'/invoice', ['file' => $file])->assertStatus(422)->assertJsonPath('success', false);
        Queue::assertNothingPushed();
    }
}
