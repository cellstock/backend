<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'logo_path',
        'about_me',
        'contact_information',
        'terms_and_conditions',
        'return_policy',
        'push_after_upload',
        'use_new_inventory_page',
    ];

    protected function casts(): array
    {
        return [
            'push_after_upload' => 'boolean',
            'use_new_inventory_page' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
