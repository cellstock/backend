<?php

namespace App\Models;

use App\Enums\MarketplaceConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketplaceConnection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'marketplace_id',
        'account_name',
        'external_account_id',
        'credentials',
        'settings',
        'webhook_secret',
        'webhook_enabled_at',
        'webhook_last_received_at',
        'webhook_activation_status',
        'webhook_activation_requested_at',
        'webhook_activated_at',
        'webhook_activation_error',
        'status',
        'last_tested_at',
        'last_synced_at',
        'last_error',
    ];

    protected $hidden = [
        'credentials',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'webhook_secret' => 'encrypted',
            'webhook_enabled_at' => 'datetime',
            'webhook_last_received_at' => 'datetime',
            'webhook_activation_requested_at' => 'datetime',
            'webhook_activated_at' => 'datetime',
            'status' => MarketplaceConnectionStatus::class,
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MarketplaceSyncRun::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(
            MarketplaceOrder::class
        );
    }

    public function offers(): HasMany
    {
        return $this->hasMany(MarketplaceOffer::class);
    }

    public function shippingProfiles(): HasMany
    {
        return $this->hasMany(MarketplaceShippingProfile::class);
    }

    public function merchantAddresses(): HasMany
    {
        return $this->hasMany(MarketplaceMerchantAddress::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(MarketplaceWebhookDelivery::class);
    }
}
