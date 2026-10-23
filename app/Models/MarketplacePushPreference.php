<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplacePushPreference extends Model
{
    protected $fillable = [
        'user_id',
        'marketplace_slug',
        'slots',
        'last_pushed_at',
        'market_last_pushed',
    ];

    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'last_pushed_at' => 'datetime',
            'market_last_pushed' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<array{key: string, from: string, to: string, active: bool, start_at: string, every: string}>
     */
    public static function defaultSlots(): array
    {
        return [
            [
                'key' => '07-12',
                'from' => '07:00',
                'to' => '12:00',
                'active' => true,
                'start_at' => '07:00',
                'every' => '01:00',
            ],
            [
                'key' => '12-18',
                'from' => '12:00',
                'to' => '18:00',
                'active' => true,
                'start_at' => '12:00',
                'every' => '01:00',
            ],
            [
                'key' => '18-23',
                'from' => '18:00',
                'to' => '23:00',
                'active' => true,
                'start_at' => '18:00',
                'every' => '01:00',
            ],
            [
                'key' => '23-07',
                'from' => '23:00',
                'to' => '07:00',
                'active' => true,
                'start_at' => '23:00',
                'every' => '01:00',
            ],
        ];
    }

    /** @return list<string> */
    public static function supportedSlugs(): array
    {
        return ['refurbed', 'back-market-eu'];
    }
}
