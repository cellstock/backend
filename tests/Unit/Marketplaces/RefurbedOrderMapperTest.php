<?php

namespace Tests\Unit\Marketplaces;

use App\Services\Marketplaces\Mappers\RefurbedOrderMapper;
use InvalidArgumentException;
use Tests\TestCase;

class RefurbedOrderMapperTest extends TestCase
{
    public function test_it_maps_a_refurbed_order(): void
    {
        $source = $this->fixture()['orders'][0];

        $result = app(
            RefurbedOrderMapper::class
        )->mapOrder($source);

        $this->assertSame(
            'test-order-1001',
            $result['external_order_id']
        );

        $this->assertSame('NEW', $result['status']);
        $this->assertSame('DE', $result['country_code']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('359.99', $result['subtotal']);
        $this->assertSame('0.00', $result['shipping_amount']);
        $this->assertSame('0.00', $result['tax_amount']);
        $this->assertSame('359.99', $result['total_amount']);
        $this->assertSame(
            'STRIPE_CARDS',
            $result['payment_method']
        );

        $this->assertSame(
            'customer@example.com',
            $result['shipping_address']['first_name']
                === 'Test'
                ? $result['raw_payload']['customer_email']
                : null
        );

        $this->assertNotNull($result['ordered_at']);
        $this->assertNotNull($result['last_synced_at']);
    }

    public function test_it_maps_refurbed_order_items(): void
    {
        $source = $this->fixture()['orders'][0];

        $items = app(
            RefurbedOrderMapper::class
        )->mapItems($source);

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertSame(
            'test-item-1001',
            $item['external_order_item_id']
        );

        $this->assertSame(
            'test-offer-1001',
            $item['external_listing_id']
        );

        $this->assertSame('TEST-SKU-001', $item['sku']);
        $this->assertSame(1, $item['quantity']);
        $this->assertSame('359.99', $item['unit_price']);
        $this->assertSame('30.60', $item['commission_amount']);
        $this->assertSame('EUR', $item['currency']);
        $this->assertSame('NEW', $item['status']);
        $this->assertSame('A', $item['condition']);
    }

    public function test_it_rejects_an_order_without_an_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RefurbedOrderMapper::class)->mapOrder([]);
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
