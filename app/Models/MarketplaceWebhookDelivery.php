<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceWebhookDelivery extends Model
{
    protected $fillable = [
        'marketplace_connection_id',
        'event_type',
        'external_id',
        'status',
        'payload',
        'attempts',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }
}
