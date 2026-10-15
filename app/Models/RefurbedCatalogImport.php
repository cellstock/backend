<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefurbedCatalogImport extends Model
{
    protected $attributes = [
        'total_rows' => 0,
        'imported_rows' => 0,
        'failed_rows' => 0,
    ];

    protected $fillable = [
        'user_id',
        'original_name',
        'stored_path',
        'status',
        'total_rows',
        'imported_rows',
        'failed_rows',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefurbedCatalogItem::class, 'catalog_import_id');
    }
}
