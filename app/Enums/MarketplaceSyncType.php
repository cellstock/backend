<?php

namespace App\Enums;

enum MarketplaceSyncType: string
{
    case Full = 'full';
    case Orders = 'orders';
    case Products = 'products';
    case Inventory = 'inventory';
}