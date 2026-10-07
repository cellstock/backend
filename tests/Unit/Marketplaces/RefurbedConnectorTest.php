<?php

namespace Tests\Unit\Marketplaces;

use App\Exceptions\RefurbedApiException;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\Sync\RefurbedOrderSynchronizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RefurbedConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.refurbed.base_url' => 'https://sandbox.refurbed.test',
            'services.refurbed.retry_times' => 3,
            'services.refurbed.retry_delay' => 0,
            'services.refurbed.max_retry_delay' => 0,
        ]);
    }

    public function test_it_creates_an_offer_with_required_taxation(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::response([
                'offer' => ['id' => 'offer-901', 'sku' => 'TEST-901'],
            ]),
        ]);

        $offer = $this->connector()->createOffer($this->connection([
            'api_key' => 'sandbox-secret-token-value',
        ]), [
            'instance_id' => 'instance-1',
            'sku' => 'TEST-901',
            'grading' => 'A',
            'taxation' => 'GROSS',
            'warranty' => 'M12',
            'stock' => 2,
            'shipping_profile_id' => 'shipping-1',
            'reference_currency_code' => 'EUR',
            'reference_price' => '499.00',
        ]);

        $this->assertSame('offer-901', $offer['id']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refb.merchant.v1.OfferService/CreateOffer')
            && $request['taxation'] === 'GROSS'
            && $request['sku'] === 'TEST-901'
        );
    }

    public function test_it_creates_a_shipping_profile_and_returns_every_response_field(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::response([
                'shipping_profile' => [
                    'id' => 'profile-12',
                    'name' => 'Germany warehouse',
                    'num_offers_assigned' => 0,
                    'source' => ['address' => ['country_code' => 'DE']],
                    'destinations' => [['country_code' => 'FR']],
                ],
            ]),
        ]);

        $payload = [
            'name' => 'Germany warehouse',
            'source' => ['address' => ['country_code' => 'DE']],
            'destinations' => [['country_code' => 'FR']],
        ];
        $profile = $this->connector()->createShippingProfile(
            $this->connection(['api_key' => 'sandbox-secret-token-value']),
            $payload
        );

        $this->assertSame('profile-12', $profile['id']);
        $this->assertSame('FR', $profile['destinations'][0]['country_code']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refb.merchant.v1.ShippingProfileService/CreateShippingProfile')
            && $request['name'] === 'Germany warehouse'
        );
    }

    public function test_it_fetches_all_order_pages_in_stable_id_order(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::sequence()
                ->push([
                    'orders' => [
                        ['id' => '101'],
                        ['id' => '102'],
                    ],
                    'has_more' => true,
                ])
                ->push([
                    'orders' => [
                        ['id' => '103'],
                    ],
                    'has_more' => false,
                ]),
        ]);

        $synchronizer = Mockery::mock(
            RefurbedOrderSynchronizer::class
        );
        $synchronizer->shouldReceive('synchronize')->times(3);

        $result = $this->connector($synchronizer)->sync(
            $this->connection([
                'api_key' => 'sandbox-secret-token-value',
            ]),
            'orders'
        );

        $this->assertSame([
            'processed_count' => 3,
            'success_count' => 3,
            'failed_count' => 0,
        ], $result);

        Http::assertSentCount(2);

        Http::assertSentInOrder([
            function (Request $request): bool {
                return $request->hasHeader(
                    'Authorization',
                    'Plain sandbox-secret-token-value'
                ) && $request['pagination'] === ['limit' => 100]
                    && $request['sort'] === [
                        'by' => 'ID',
                        'order' => 'ASC',
                    ];
            },
            function (Request $request): bool {
                return $request['pagination'] === [
                    'limit' => 100,
                    'starting_after' => '102',
                ];
            },
        ]);
    }

    public function test_it_tests_the_connection_and_loads_site_markets(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::response([
                'markets' => [
                    [
                        'code' => 'DE',
                        'name' => 'Germany',
                        'currency_code' => 'EUR',
                        'is_site' => true,
                    ],
                ],
                'has_more' => false,
            ]),
        ]);

        $result = $this->connector()->testConnection([
            'api_key' => 'sandbox-secret-token-value',
        ]);

        $this->assertSame(1, $result['metadata']['available_markets']);
        $this->assertSame(1, $result['metadata']['active_markets']);

        Http::assertSent(function (Request $request): bool {
            return $request['pagination'] === ['limit' => 100]
                && $request['filter'] === [
                    'is_site' => ['value' => true],
                ]
                && $request->hasHeader(
                    'Authorization',
                    'Plain sandbox-secret-token-value'
                );
        });
    }

    public function test_it_rejects_a_connection_without_a_saved_token(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Refurbed API credentials are missing.'
        );

        $this->connector()->sync(
            $this->connection([]),
            'orders'
        );
    }

    public function test_it_retries_a_rate_limited_request(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::sequence()
                ->push(
                    ['message' => 'rate limited'],
                    429,
                    [
                        'X-RateLimit-Remaining' => '0',
                        'X-RateLimit-Reset' => (string) time(),
                    ]
                )
                ->push([
                    'orders' => [],
                    'has_more' => false,
                ]),
        ]);

        $result = $this->connector()->sync(
            $this->connection([
                'api_key' => 'sandbox-secret-token-value',
            ]),
            'orders'
        );

        $this->assertSame(0, $result['processed_count']);
        Http::assertSentCount(2);
    }

    public function test_it_does_not_retry_authentication_errors(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::response(
                ['code' => 16, 'message' => 'token malformed', 'details' => []],
                401
            ),
        ]);

        try {
            $this->connector()->sync(
                $this->connection([
                    'api_key' => 'invalid-sandbox-token-value',
                ]),
                'orders'
            );

            $this->fail('Expected the API request to fail.');
        } catch (RefurbedApiException $exception) {
            $this->assertSame(16, $exception->grpcCode());
            $this->assertSame(401, $exception->httpStatus());
            $this->assertStringContainsString('authentication failed', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_it_preserves_batch_offer_errors_with_their_sku(): void
    {
        Http::fake([
            'sandbox.refurbed.test/*' => Http::response([
                'results' => [
                    ['status' => ['code' => 0], 'offer' => ['sku' => 'OK-1']],
                    ['status' => ['code' => 5, 'message' => 'offer not found']],
                ],
            ]),
        ]);

        $results = $this->connector()->updateOfferStocks(
            $this->connection(['api_key' => 'sandbox-secret-token-value']),
            ['OK-1' => 4, 'MISSING-2' => 0],
        );

        $this->assertSame('OK-1', $results[0]['sku']);
        $this->assertSame('MISSING-2', $results[1]['sku']);
        $this->assertSame(5, $results[1]['status']['code']);
    }

    public function test_it_returns_the_next_offer_cursor_for_inventory_pagination(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response([
            'offers' => [['id' => 'offer-10'], ['id' => 'offer-11']],
            'has_more' => true,
        ])]);

        $result = $this->connector()->listOffers(
            $this->connection(['api_key' => 'sandbox-secret-token-value']),
            null,
            25,
        );

        $this->assertTrue($result['has_more']);
        $this->assertSame('offer-11', $result['next_cursor']);
    }

    public function test_it_updates_the_refurbed_shipping_label_address(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response(['address' => ['country_code' => 'FR']])]);
        $order = $this->order('REF-123');

        $this->connector()->updateShippingLabelAddress($order, [
            'first_name' => 'Nicolas',
            'family_name' => 'Hafidhou',
            'street_name' => 'Rue Auguste Renoir',
            'house_no' => '45',
            'town' => 'Saint-Nazaire',
            'post_code' => '44600',
            'country_code' => 'FR',
            'phone' => '0695277381',
        ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refb.merchant.v1.OrderService/UpdateShippingLabelAddress')
            && $request['order_id'] === 'REF-123'
            && $request['address']['country_code'] === 'FR'
        );
    }

    public function test_it_creates_a_refurbed_shipping_label(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response([
            'shipping_label' => [
                'id' => 'label-12',
                'label_printer_url' => 'https://labels.example/label-12',
                'tracking_data' => ['tracking_number' => 'TRACK-12'],
                'parcel_weight' => 1.5,
            ],
        ])]);
        $order = $this->order('REF-789');

        $label = $this->connector()->createShippingLabel($order, [
            'merchant_address_id' => 'address-4',
            'parcel_weight' => 1.5,
            'carrier' => 'dhl-express',
        ]);

        $this->assertSame('label-12', $label['id']);
        $this->assertSame('TRACK-12', $label['tracking_data']['tracking_number']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refb.merchant.v1.OrderService/CreateShippingLabel')
            && $request['order_id'] === 'REF-789'
            && $request['merchant_address_id'] === 'address-4'
            && $request['parcel_weight'] === 1.5
            && $request['carrier'] === 'DHL_EXPRESS'
        );
    }

    public function test_it_reports_plain_text_shipping_label_errors(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response(
            '<html><body>merchant address not found</body></html>',
            422,
            ['Content-Type' => 'text/html']
        )]);

        try {
            $this->connector()->createShippingLabel($this->order('REF-789'), [
                'merchant_address_id' => 'missing-address',
                'parcel_weight' => 1.5,
                'carrier' => 'DHL',
            ]);

            $this->fail('Expected the shipping label request to fail.');
        } catch (RefurbedApiException $exception) {
            $this->assertSame(422, $exception->httpStatus());
            $this->assertStringContainsString(
                'merchant address not found',
                $exception->getMessage()
            );
            $this->assertSame(422, $exception->details()['http_status']);
            $this->assertSame('text/html', $exception->details()['content_type']);
        }
    }

    public function test_it_loads_merchant_addresses_from_refurbed(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response([[
            'merchant_addresses' => [[
                'id' => '4',
                'company_name' => 'Cats Inc.',
                'type' => 'DELIVERY',
                'country_code' => 'DE',
            ]],
        ]])]);

        $addresses = $this->connector()->listMerchantAddresses(
            $this->order('REF-789')
        );

        $this->assertSame('4', $addresses[0]['id']);
        $this->assertSame('DELIVERY', $addresses[0]['type']);
        Http::assertSent(fn (Request $request): bool => str_ends_with(
            $request->url(),
            '/refb.merchant.v1.MerchantService/ListMerchantAddresses'
        ) && $request->data() === []
        );
    }

    public function test_it_uploads_an_invoice_as_a_refurbed_stream(): void
    {
        Http::fake(['sandbox.refurbed.test/*' => Http::response(['success' => true])]);
        $order = $this->order('REF-456');

        $this->connector()->uploadOrderInvoice($order, base64_encode('pdf-content'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refb.merchant.v1.OrderService/SetOrderInvoiceStream')
            && $request->data() === [
                ['meta' => ['order_id' => 'REF-456']],
                ['data' => base64_encode('pdf-content')],
            ]
        );
    }

    private function connector(
        ?RefurbedOrderSynchronizer $synchronizer = null
    ): RefurbedConnector {
        return new RefurbedConnector(
            new Marketplace,
            $synchronizer ?? Mockery::mock(
                RefurbedOrderSynchronizer::class
            )
        );
    }

    private function connection(
        array $credentials
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection;
        $connection->credentials = $credentials;

        return $connection;
    }

    private function order(string $externalId): MarketplaceOrder
    {
        $order = new MarketplaceOrder;
        $order->external_order_id = $externalId;
        $order->setRelation('connection', $this->connection([
            'api_key' => 'sandbox-secret-token-value',
        ]));

        return $order;
    }
}
