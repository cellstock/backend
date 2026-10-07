<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceShippingProfile extends Model
{
    protected $fillable = ['marketplace_connection_id', 'external_id', 'version', 'is_current', 'is_deletable', 'name', 'source_country_code', 'source_country_market_name', 'num_offers_assigned', 'marketplace_created_at', 'raw_payload', 'last_synced_at'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'is_deletable' => 'boolean', 'num_offers_assigned' => 'integer', 'raw_payload' => 'encrypted:array', 'marketplace_created_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(MarketplaceShippingProfileDestination::class);
    }
}
