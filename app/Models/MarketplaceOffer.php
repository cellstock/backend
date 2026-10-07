<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOffer extends Model
{
    protected $fillable = [
        'marketplace_connection_id', 'external_offer_id', 'instance_id', 'instance_name', 'ean',
        'sku', 'state', 'grading', 'warranty', 'stock', 'shipping_profile_id',
        'reference_currency_code', 'reference_price', 'reference_min_price', 'market_codes', 'raw_payload',
        'marketplace_created_at', 'last_synced_at', 'tags', 'pending_upload', 'is_active',
        'listing_details',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'reference_price' => 'decimal:2',
            'reference_min_price' => 'decimal:2',
            'market_codes' => 'array',
            'raw_payload' => 'encrypted:array',
            'tags' => 'array',
            'pending_upload' => 'boolean',
            'is_active' => 'boolean',
            'listing_details' => 'array',
            'marketplace_created_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }
}
