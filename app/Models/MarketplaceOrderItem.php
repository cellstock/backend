<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderItem extends Model
{
    protected $fillable = [
        'marketplace_order_id',
        'external_order_item_id',
        'external_listing_id',
        'external_product_id',
        'sku',
        'title',
        'brand',
        'quantity',
        'unit_price',
        'shipping_amount',
        'shipping_override_amount',
        'commission_amount',
        'tax_amount',
        'currency',
        'status',
        'condition',
        'return_reason',
        'return_message',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'shipping_override_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'raw_payload' => 'encrypted:array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceOrder::class,
            'marketplace_order_id'
        );
    }
}
