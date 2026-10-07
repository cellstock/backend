<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceShippingProfileDestination extends Model
{
    protected $fillable = [
        'marketplace_shipping_profile_id', 'market_code', 'market_name', 'is_site_market',
        'currency_code', 'shipping_costs', 'site_market_currency_code',
        'site_market_shipping_costs', 'min_delivery_days', 'max_delivery_days', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_site_market' => 'boolean', 'shipping_costs' => 'decimal:2',
            'site_market_shipping_costs' => 'decimal:2', 'min_delivery_days' => 'integer',
            'max_delivery_days' => 'integer', 'raw_payload' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShippingProfile::class, 'marketplace_shipping_profile_id');
    }
}
