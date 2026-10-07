<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_identifiers' => 'encrypted:array',
            'raw_payload' => 'encrypted:array',
            'item_within_trial_period' => 'boolean',
            'return_initiated_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
