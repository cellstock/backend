<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceMerchantAddress extends Model
{
    protected $fillable = [
        'marketplace_connection_id',
        'external_id',
        'version',
        'label',
        'first_name',
        'family_name',
        'company_name',
        'country_code',
        'post_code',
        'town',
        'street_name',
        'house_no',
        'supplement',
        'phone_number',
        'type',
        'raw_payload',
        'last_synced_at',
        'sync_status',
        'sync_error',
        'pending_action',
        'retry_count',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'encrypted:array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceConnection::class,
            'marketplace_connection_id'
        );
    }
}
