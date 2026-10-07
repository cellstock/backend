<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Seeder;

class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        $marketplaces = [
            [
                'name' => 'Back Market',
                'slug' => 'back-market',
                'logo' => '/images/marketplaces/back-market.svg',
                'description' => 'Manage Back Market orders and inventory.',
                'capabilities' => [
                    'orders',
                    'inventory',
                ],
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Refurbed',
                'slug' => 'refurbed',
                'logo' => '/images/marketplaces/refurbed.svg',
                'description' => 'Manage Refurbed orders.',
                'capabilities' => [
                    'orders',
                ],
                'is_active' => true,
                'display_order' => 2,
            ],
        ];

        foreach ($marketplaces as $marketplace) {
            Marketplace::updateOrCreate(
                ['slug' => $marketplace['slug']],
                $marketplace,
            );
        }
    }
}
