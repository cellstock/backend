<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceShippingLabel extends Model
{
    protected $fillable = [
        'marketplace_order_id',
        'external_id',
        'normal_printer_urls',
        'label_printer_url',
        'carrier',
        'tracking_number',
        'tracking_url',
        'parcel_weight',
        'merchant_address',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'normal_printer_urls' => 'array',
            'merchant_address' => 'encrypted:array',
            'raw_payload' => 'encrypted:array',
            'parcel_weight' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
