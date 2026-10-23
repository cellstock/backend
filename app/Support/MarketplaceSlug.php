<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical marketplace slug helpers.
 *
 * Seeded Back Market platforms use back-market-eu|na|ap; older code and some
 * API clients still send the legacy "back-market" family name.
 */
final class MarketplaceSlug
{
    public static function isRefurbed(?string $slug): bool
    {
        return strtolower(trim((string) $slug)) === 'refurbed';
    }

    public static function isBackMarket(?string $slug): bool
    {
        return str_starts_with(strtolower(trim((string) $slug)), 'back-market');
    }

    public static function isInventorySupported(?string $slug): bool
    {
        return self::isRefurbed($slug) || self::isBackMarket($slug);
    }

    public static function sameFamily(?string $a, ?string $b): bool
    {
        if (self::isRefurbed($a) && self::isRefurbed($b)) {
            return true;
        }

        if (self::isBackMarket($a) && self::isBackMarket($b)) {
            return true;
        }

        return strtolower(trim((string) $a)) === strtolower(trim((string) $b));
    }

    /**
     * Constrain a marketplaces query to the family of $preferSlug.
     *
     * @param  Builder<\App\Models\Marketplace>|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrainQuery(mixed $query, string $preferSlug): void
    {
        if (self::isBackMarket($preferSlug)) {
            $query->where('slug', 'like', 'back-market%');

            return;
        }

        $query->where('slug', strtolower(trim($preferSlug)));
    }

    /**
     * @param  Builder<\App\Models\Marketplace>|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrainInventoryQuery(mixed $query): void
    {
        $query->where(function ($nested): void {
            $nested
                ->where('slug', 'refurbed')
                ->orWhere('slug', 'like', 'back-market%');
        });
    }
}
