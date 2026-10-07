<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\MarketplaceSyncStatus;
use App\Enums\MarketplaceSyncType;

class MarketplaceSyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'marketplace_connection_id',
        'type',
        'status',
        'processed_count',
        'success_count',
        'failed_count',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'type' => MarketplaceSyncType::class,
            'status' => MarketplaceSyncStatus::class,
            'processed_count' => 'integer',
            'success_count' => 'integer',
            'failed_count' => 'integer',
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
        return $this->belongsTo(
            MarketplaceConnection::class,
            'marketplace_connection_id'
        );
    }
}