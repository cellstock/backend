<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOperation extends Model
{
    protected $fillable = [
        'user_id', 'marketplace_connection_id', 'type', 'entity_type', 'entity_id',
        'payload', 'result', 'status', 'error_message', 'attempts', 'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'result' => 'encrypted:array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }
}
