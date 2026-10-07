<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceOrder extends Model
{
    protected $fillable = [
        'marketplace_connection_id',
        'external_order_id',
        'status',
        'workflow_status',
        'country_code',
        'currency',
        'subtotal',
        'shipping_amount',
        'tax_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'vat_number',
        'custom_invoice_path',
        'invoice_url',
        'invoice_url_cached_at',
        'tracking_number',
        'tracking_url',
        'shipper',
        'shipping_address',
        'billing_address',
        'raw_payload',
        'ordered_at',
        'modified_at',
        'paid_at',
        'shipped_at',
        'last_synced_at',
        'internal_comment',
        'contact_before',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',

            'shipping_address' => 'encrypted:array',
            'billing_address' => 'encrypted:array',
            'raw_payload' => 'encrypted:array',

            'ordered_at' => 'datetime',
            'modified_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'contact_before' => 'date',
            'invoice_url_cached_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceConnection::class,
            'marketplace_connection_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            MarketplaceOrderItem::class
        );
    }

    public function shippingLabels(): HasMany
    {
        return $this->hasMany(MarketplaceShippingLabel::class)->latest();
    }
}
