<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
