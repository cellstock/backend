<?php

namespace App\Services\Inventory;

use App\Enums\MarketplaceConnectionStatus;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePushPreference;
use App\Models\User;
use App\Services\Marketplaces\Connectors\BackMarketConnector;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

class MarketplacePushService
{
    public function __construct(
        private readonly MarketplaceConnectorManager $connectors,
    ) {}

    /**
     * @return list<array{marketplace_slug: string, name: string, logo: string|null, connected: bool, preference: array<string, mixed>}>
     */
    public function preferencesFor(User $user): array
    {
        $connected = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->whereIn(
                'slug',
                MarketplacePushPreference::supportedSlugs()
            ))
            ->with('marketplace')
            ->get()
            ->keyBy(fn (MarketplaceConnection $connection) => $connection->marketplace?->slug);

        $stored = MarketplacePushPreference::query()
            ->whereBelongsTo($user)
            ->whereIn('marketplace_slug', MarketplacePushPreference::supportedSlugs())
            ->get()
            ->keyBy('marketplace_slug');

        $labels = [
            'back-market-eu' => [
                'name' => 'Back Market',
                'logo' => '/images/marketplaces/back-market.png',
            ],
            'refurbed' => [
                'name' => 'Refurbed',
                'logo' => '/images/marketplaces/refurbed.png',
            ],
        ];

        $items = [];

        foreach (MarketplacePushPreference::supportedSlugs() as $slug) {
            $preference = $stored->get($slug) ?? MarketplacePushPreference::query()->create([
                'user_id' => $user->id,
                'marketplace_slug' => $slug,
                'slots' => MarketplacePushPreference::defaultSlots(),
            ]);

            $connection = $connected->get($slug);
            $pendingCount = 0;
            if ($connection) {
                $pendingCount = MarketplaceOffer::query()
                    ->where('marketplace_connection_id', $connection->id)
                    ->where('pending_upload', true)
                    ->count();
            }

            $items[] = [
                'marketplace_slug' => $slug,
                'name' => $labels[$slug]['name'],
                'logo' => $labels[$slug]['logo'],
                'connected' => $connection !== null,
                'pending_count' => $pendingCount,
                'preference' => $this->present($preference),
            ];
        }

        // Match the mockup order: Back Market first, then Refurbed.
        usort($items, function (array $left, array $right): int {
            $order = ['back-market-eu' => 0, 'refurbed' => 1];

            return ($order[$left['marketplace_slug']] ?? 99)
                <=> ($order[$right['marketplace_slug']] ?? 99);
        });

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, mixed>
     */
    public function updateSlots(User $user, string $marketplaceSlug, array $slots): array
    {
        if (! in_array($marketplaceSlug, MarketplacePushPreference::supportedSlugs(), true)) {
            throw new RuntimeException('Unsupported marketplace for push preferences.');
        }

        $preference = MarketplacePushPreference::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'marketplace_slug' => $marketplaceSlug,
            ],
            ['slots' => MarketplacePushPreference::defaultSlots()]
        );

        $preference->update(['slots' => $this->normalizeSlots($slots)]);

        return $this->present($preference->refresh());
    }

    /**
     * Country/locale push rows for the inventory "Push to marketplaces" panel.
     *
     * @return list<array{
     *   id: string,
     *   marketplace_slug: string,
     *   market_code: string,
     *   name: string,
     *   logo: string|null,
     *   connected: bool,
     *   enabled: bool,
     *   pending_count: int,
     *   last_pushed_at: string|null,
     *   disabled_message: string|null
     * }>
     */
    public function targetsFor(User $user): array
    {
        $connections = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->whereIn(
                'slug',
                MarketplacePushPreference::supportedSlugs()
            ))
            ->with('marketplace')
            ->get()
            ->keyBy(fn (MarketplaceConnection $connection) => $connection->marketplace?->slug);

        $preferences = MarketplacePushPreference::query()
            ->whereBelongsTo($user)
            ->whereIn('marketplace_slug', MarketplacePushPreference::supportedSlugs())
            ->get()
            ->keyBy('marketplace_slug');

        $logos = [
            'back-market-eu' => '/images/marketplaces/back-market.png',
            'refurbed' => '/images/marketplaces/refurbed.png',
        ];

        $targets = [];

        foreach (MarketplacePushPreference::supportedSlugs() as $slug) {
            $connection = $connections->get($slug);
            if (! $connection) {
                continue;
            }

            $preference = $preferences->get($slug) ?? MarketplacePushPreference::query()->create([
                'user_id' => $user->id,
                'marketplace_slug' => $slug,
                'slots' => MarketplacePushPreference::defaultSlots(),
            ]);

            $lastPushedByMarket = $preference->market_last_pushed ?? [];
            $markets = collect($connection->settings['markets'] ?? [])
                ->filter(fn ($market) => is_array($market) && filled($market['code'] ?? null))
                ->values();

            foreach ($markets as $market) {
                $code = (string) $market['code'];
                $enabled = (bool) ($market['manage_offers'] ?? false)
                    || (bool) ($market['manage_orders'] ?? false);
                $name = $this->targetDisplayName($slug, $code, (string) ($market['name'] ?? ''));
                $pendingCount = 0;

                if ($enabled) {
                    $pendingCount = MarketplaceOffer::query()
                        ->where('marketplace_connection_id', $connection->id)
                        ->where('pending_upload', true)
                        ->where(function ($query) use ($code): void {
                            $query
                                ->whereJsonContains('market_codes', $code)
                                ->orWhereJsonContains('market_codes', strtoupper($code))
                                ->orWhereJsonContains('market_codes', strtolower($code));
                        })
                        ->count();
                }

                $lastPushed = $lastPushedByMarket[$code]
                    ?? $lastPushedByMarket[strtoupper($code)]
                    ?? $preference->last_pushed_at?->toISOString();

                $targets[] = [
                    'id' => $slug.':'.$code,
                    'marketplace_slug' => $slug,
                    'market_code' => $code,
                    'name' => $name,
                    'logo' => $logos[$slug] ?? null,
                    'connected' => true,
                    'enabled' => $enabled,
                    'pending_count' => $pendingCount,
                    'last_pushed_at' => $lastPushed,
                    'disabled_message' => $enabled
                        ? null
                        : 'The "Push '.$name.'" option is disabled. Enable this market in marketplace settings to push inventory.',
                ];
            }
        }

        usort($targets, function (array $left, array $right): int {
            $order = ['back-market-eu' => 0, 'refurbed' => 1];
            $bySlug = ($order[$left['marketplace_slug']] ?? 99)
                <=> ($order[$right['marketplace_slug']] ?? 99);
            if ($bySlug !== 0) {
                return $bySlug;
            }

            return strcmp($left['name'], $right['name']);
        });

        return $targets;
    }

    /**
     * @param  list<string>  $targets  e.g. ["refurbed:AT", "back-market-eu:fr-fr"]
     * @return list<array{marketplace: string, market_code?: string, pushed: int, skipped?: string}>
     */
    public function pushNow(User $user, array $targets): array
    {
        $grouped = [];

        foreach ($targets as $target) {
            $target = trim((string) $target);
            if ($target === '') {
                continue;
            }

            if (str_contains($target, ':')) {
                [$slug, $code] = explode(':', $target, 2);
            } else {
                $slug = $target;
                $code = null;
            }

            if (! in_array($slug, MarketplacePushPreference::supportedSlugs(), true)) {
                continue;
            }

            $grouped[$slug][] = $code;
        }

        $results = [];

        foreach ($grouped as $slug => $codes) {
            $preference = MarketplacePushPreference::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'marketplace_slug' => $slug,
                ],
                ['slots' => MarketplacePushPreference::defaultSlots()]
            );

            $result = $this->pushForPreference($preference);
            $preference->refresh();
            $now = now()->toISOString();
            $marketLast = $preference->market_last_pushed ?? [];
            $wroteMarket = false;

            foreach ($codes as $code) {
                if ($code === null || $code === '') {
                    continue;
                }
                $marketLast[$code] = $now;
                $wroteMarket = true;
                $results[] = [
                    'marketplace' => $slug,
                    'market_code' => $code,
                    'pushed' => (int) ($result['pushed'] ?? 0),
                    'skipped' => $result['skipped'] ?? null,
                ];
            }

            if (! $wroteMarket) {
                $results[] = $result;
            }

            $preference->update([
                'market_last_pushed' => $marketLast,
            ]);
        }

        return $results;
    }

    private function targetDisplayName(string $slug, string $code, string $name): string
    {
        $name = trim($name);
        if ($name !== '' && preg_match('/\b(AT|BE|DE|DK|ES|FI|FR|GR|IE|IT|LU|NL|PL|PT|SE|SK|GB|UK)\b/i', $name)) {
            return $name;
        }

        if (str_starts_with($slug, 'back-market')) {
            $country = strtoupper((string) (explode('-', strtolower($code))[1] ?? $code));

            return 'BackMarket '.$country;
        }

        return 'Refurbed '.strtoupper($code);
    }

    public function duePreferences(?CarbonInterface $now = null): iterable
    {
        $now ??= now();

        return MarketplacePushPreference::query()
            ->with('user')
            ->whereIn('marketplace_slug', MarketplacePushPreference::supportedSlugs())
            ->get()
            ->filter(fn (MarketplacePushPreference $preference) => $this->shouldPush($preference, $now));
    }

    public function shouldPush(MarketplacePushPreference $preference, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $activeSlot = $this->activeSlot($preference->slots ?? [], $now);

        if ($activeSlot === null) {
            return false;
        }

        $everyMinutes = $this->minutesFromTime((string) ($activeSlot['every'] ?? '01:00'));
        if ($everyMinutes < 1) {
            $everyMinutes = 60;
        }

        if ($preference->last_pushed_at === null) {
            return true;
        }

        return $preference->last_pushed_at->lte(
            Carbon::instance($now)->subMinutes($everyMinutes)
        );
    }

    /**
     * @return array{pushed: int, marketplace: string, skipped?: string}
     */
    public function pushForPreference(MarketplacePushPreference $preference): array
    {
        $user = $preference->user;
        if (! $user) {
            return ['pushed' => 0, 'marketplace' => $preference->marketplace_slug, 'skipped' => 'missing_user'];
        }

        $connection = MarketplaceConnection::query()
            ->whereBelongsTo($user)
            ->where('status', MarketplaceConnectionStatus::Connected->value)
            ->whereHas('marketplace', fn ($query) => $query->where('slug', $preference->marketplace_slug))
            ->with('marketplace')
            ->first();

        if (! $connection) {
            $preference->update(['last_pushed_at' => now()]);

            return [
                'pushed' => 0,
                'marketplace' => $preference->marketplace_slug,
                'skipped' => 'not_connected',
            ];
        }

        $result = $this->pushConnection($connection);
        $preference->update(['last_pushed_at' => now()]);

        return $result;
    }

    /**
     * @return array{pushed: int, marketplace: string}
     */
    public function pushConnection(MarketplaceConnection $connection): array
    {
        $connector = $this->connectors->connector($connection->marketplace);
        $slug = $connection->marketplace?->slug ?? '';

        $offers = MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get();

        if ($offers->isEmpty()) {
            return ['pushed' => 0, 'marketplace' => $slug];
        }

        if ($slug === 'refurbed') {
            $stocks = [];
            foreach ($offers as $offer) {
                $stocks[(string) $offer->sku] = (int) $offer->stock;
            }

            if ($connector instanceof RefurbedConnector) {
                $connector->updateOfferStocks($connection, $stocks);
            }

            MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->whereIn('sku', array_keys($stocks))
                ->update(['pending_upload' => false, 'last_synced_at' => now()]);

            return ['pushed' => count($stocks), 'marketplace' => $slug];
        }

        if (str_starts_with($slug, 'back-market')) {
            $rows = [];
            foreach ($offers as $offer) {
                $productId = trim((string) (
                    $offer->instance_id
                    ?: data_get($offer->raw_payload, 'product_id')
                    ?: data_get($offer->raw_payload, 'backmarket_id')
                    ?: ''
                ));
                if ($productId === '') {
                    continue;
                }

                $price = (string) ($offer->reference_price ?? '0');
                $grade = match (strtoupper((string) ($offer->grading ?? 'A'))) {
                    'AA', 'PREMIUM' => 'PREMIUM',
                    'A', 'EXCELLENT' => 'EXCELLENT',
                    'B', 'GOOD' => 'GOOD',
                    'C', 'FAIR' => 'FAIR',
                    default => 'EXCELLENT',
                };

                $rows[] = [
                    'sku' => (string) $offer->sku,
                    'product_id' => $productId,
                    'quantity' => (int) $offer->stock,
                    'price' => $price,
                    'min_price' => (string) ($offer->reference_min_price ?? $price),
                    'max_price' => $price,
                    'comment' => trim((string) data_get($offer->listing_details, 'description', '')),
                    'warranty_delay' => 12,
                    'grade' => $grade,
                ];
            }

            if ($connector instanceof BackMarketConnector) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    if ($chunk !== []) {
                        $connector->upsertListings($connection, $chunk);
                    }
                }
            }

            if ($rows !== []) {
                MarketplaceOffer::query()
                    ->where('marketplace_connection_id', $connection->id)
                    ->whereIn('sku', array_column($rows, 'sku'))
                    ->update(['pending_upload' => false, 'last_synced_at' => now()]);
            }

            return ['pushed' => count($rows), 'marketplace' => $slug];
        }

        throw new RuntimeException("Push is not supported for marketplace [{$slug}].");
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, mixed>|null
     */
    private function activeSlot(array $slots, CarbonInterface $now): ?array
    {
        $current = $now->format('H:i');

        foreach ($slots as $slot) {
            if (! ($slot['active'] ?? false)) {
                continue;
            }

            $from = (string) ($slot['from'] ?? '');
            $to = (string) ($slot['to'] ?? '');
            $startAt = (string) ($slot['start_at'] ?? $from);

            if ($from === '' || $to === '') {
                continue;
            }

            if (! $this->timeInRange($current, $from, $to)) {
                continue;
            }

            // Only start pushing after the configured start time inside the slot.
            if (! $this->timeReachedStart($current, $from, $to, $startAt)) {
                continue;
            }

            return $slot;
        }

        return null;
    }

    private function timeInRange(string $current, string $from, string $to): bool
    {
        if ($from <= $to) {
            return $current >= $from && $current < $to;
        }

        // Overnight window (e.g. 23:00 → 07:00).
        return $current >= $from || $current < $to;
    }

    private function timeReachedStart(string $current, string $from, string $to, string $startAt): bool
    {
        if ($from <= $to) {
            return $current >= $startAt;
        }

        // Overnight: start_at is usually near `from` (e.g. 23:00).
        if ($current >= $from) {
            return $current >= $startAt;
        }

        return true;
    }

    private function minutesFromTime(string $value): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
            return 60;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return list<array{key: string, from: string, to: string, active: bool, start_at: string, every: string}>
     */
    private function normalizeSlots(array $slots): array
    {
        $defaults = collect(MarketplacePushPreference::defaultSlots())->keyBy('key');
        $normalized = [];

        foreach ($slots as $slot) {
            $key = (string) ($slot['key'] ?? '');
            $default = $defaults->get($key);
            if ($default === null) {
                continue;
            }

            $startAt = $this->normalizeClock((string) ($slot['start_at'] ?? $default['start_at']));
            $every = $this->normalizeClock((string) ($slot['every'] ?? $default['every']));

            $normalized[] = [
                'key' => $key,
                'from' => $default['from'],
                'to' => $default['to'],
                'active' => (bool) ($slot['active'] ?? false),
                'start_at' => $startAt,
                'every' => $every,
            ];
        }

        return $normalized !== [] ? $normalized : MarketplacePushPreference::defaultSlots();
    }

    private function normalizeClock(string $value): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
            return '01:00';
        }

        $hours = min(23, max(0, (int) $matches[1]));
        $minutes = min(59, max(0, (int) $matches[2]));

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MarketplacePushPreference $preference): array
    {
        return [
            'marketplace_slug' => $preference->marketplace_slug,
            'slots' => $preference->slots ?: MarketplacePushPreference::defaultSlots(),
            'last_pushed_at' => $preference->last_pushed_at?->toISOString(),
            'updated_at' => $preference->updated_at?->toISOString(),
        ];
    }
}
