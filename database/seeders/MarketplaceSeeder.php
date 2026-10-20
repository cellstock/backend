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
                'name' => 'Back Market Europe',
                'slug' => 'back-market-eu',
                'logo' => '/images/marketplaces/back-market.png',
                'description' => 'Manage Back Market orders and inventory for the European platform (backmarket.fr).',
                'capabilities' => [
                    'orders',
                    'inventory',
                ],
                'is_active' => true,
                'display_order' => 1,
            ],
            // [
            //     'name' => 'Back Market North America',
            //     'slug' => 'back-market-na',
            //     'logo' => '/images/marketplaces/back-market.png',
            //     'description' => 'Manage Back Market orders and inventory for the North American platform (backmarket.com).',
            //     'capabilities' => [
            //         'orders',
            //         'inventory',
            //     ],
            //     'is_active' => true,
            //     'display_order' => 2,
            // ],
            // [
            //     'name' => 'Back Market Asia-Pacific',
            //     'slug' => 'back-market-ap',
            //     'logo' => '/images/marketplaces/back-market.png',
            //     'description' => 'Manage Back Market orders and inventory for the Asia-Pacific platform (backmarket.co.jp).',
            //     'capabilities' => [
            //         'orders',
            //         'inventory',
            //     ],
            //     'is_active' => true,
            //     'display_order' => 3,
            // ],
            [
                'name' => 'Refurbed',
                'slug' => 'refurbed',
                'logo' => '/images/marketplaces/refurbed.png',
                'description' => 'Manage Refurbed orders.',
                'capabilities' => [
                    'orders',
                ],
                'is_active' => true,
                'display_order' => 4,
            ],
        ];

        foreach ($marketplaces as $marketplace) {
            Marketplace::updateOrCreate(
                ['slug' => $marketplace['slug']],
                $marketplace,
            );
        }

        // Remove the old single back-market entry if it still exists
        Marketplace::where('slug', 'back-market')->delete();
    }
}
