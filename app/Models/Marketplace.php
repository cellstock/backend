<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marketplace extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'description',
        'capabilities',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(
            MarketplaceConnection::class
        );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}