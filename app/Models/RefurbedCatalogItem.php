<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefurbedCatalogItem extends Model
{
    protected $fillable = [
        'catalog_import_id',
        'instance_id',
        'sku',
        'name',
        'name_de',
        'attributes',
        'attributes_de',
        'main_category',
    ];

    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(RefurbedCatalogImport::class, 'catalog_import_id');
    }
}
