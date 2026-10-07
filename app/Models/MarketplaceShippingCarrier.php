<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceShippingCarrier extends Model
{
    protected $fillable = [
        'marketplace_connection_id',
        'name',
        'slug',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }
}
