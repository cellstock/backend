<?php

namespace App\Services\Inventory;

use App\Enums\MarketplaceConnectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceShippingProfile;
use App\Models\MarketplaceShippingProfileDestination;
use App\Models\MarketplaceShippingCarrier;
use App\Models\User;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;

class ShippingProfileService
{
    public function __construct(private readonly MarketplaceConnectorManager $connectors) {}

    public function list(User $user, array $filters = []): array
    {
        [$connection] = $this->refurbed($user);
        $base = MarketplaceShippingProfile::where('marketplace_connection_id', $connection->id);
        $countries = (clone $base)
            ->whereNotNull('source_country_code')
            ->distinct()
            ->orderBy('source_country_code')
            ->pluck('source_country_code')
            ->values()
            ->all();

        $query = $base
            ->with('destinations')
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('source_country_market_name', 'like', "%{$search}%")
                        ->orWhereHas('destinations', fn ($destination) => $destination
                            ->where('market_name', 'like', "%{$search}%")
                            ->orWhere('market_code', 'like', "%{$search}%"));
                });
            })
            ->when($filters['source_country'] ?? null, fn ($query, string $country) => $query->where('source_country_code', $country))
            ->when(($filters['deletable'] ?? null) === 'yes', fn ($query) => $query->where('is_deletable', true))
            ->when(($filters['deletable'] ?? null) === 'no', fn ($query) => $query->where('is_deletable', false));

        match ($filters['sort'] ?? 'name') {
            'newest' => $query->orderByDesc('marketplace_created_at')->orderBy('external_id'),
            'offers' => $query->orderByDesc('num_offers_assigned')->orderBy('external_id'),
            default => $query->orderBy('name')->orderBy('external_id'),
        };

        $profiles = $query->paginate((int) ($filters['per_page'] ?? 10));

        return [
            'profiles' => $profiles->getCollection()->map(fn ($profile) => $this->present($profile))->all(),
            'pagination' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'per_page' => $profiles->perPage(),
                'total' => $profiles->total(),
                'from' => $profiles->firstItem(),
                'to' => $profiles->lastItem(),
            ],
            'filters' => ['source_countries' => $countries],
        ];
    }

    public function sync(User $user): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $this->syncCarriers($connector, $connection);
        $cursor = null; $seen = [];
        do {
            $page = $connector->listShippingProfiles($connection, $cursor);
            foreach ($page['profiles'] as $profile) { $stored = $this->persist($connection, $profile); $seen[] = $stored->external_id; }
            $cursor = $page['next_cursor'] ?: null;
        } while ($page['has_more'] && $cursor);
        if (! $page['has_more']) MarketplaceShippingProfile::where('marketplace_connection_id', $connection->id)->when($seen !== [], fn ($q) => $q->whereNotIn('external_id', $seen))->delete();
        return ['synchronized' => count($seen), 'profiles' => MarketplaceShippingProfile::with('destinations')->where('marketplace_connection_id', $connection->id)->orderBy('name')->get()->map(fn ($profile) => $this->present($profile))->all()];
    }

    public function carriers(User $user): array
    {
        [$connection] = $this->refurbed($user);

        return MarketplaceShippingCarrier::where('marketplace_connection_id', $connection->id)
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn ($carrier) => [
                'name' => $carrier->name,
                'slug' => $carrier->slug,
            ])
            ->all();
    }

    public function create(User $user, array $payload): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $this->validateCarrierSlugs($connection, $payload);
        return $this->present($this->persist($connection, $connector->createShippingProfile($connection, $payload)));
    }

    public function update(User $user, string $id, array $payload): array
    {
        [$connection, $connector] = $this->refurbed($user);
        $this->owned($connection, $id);
        $this->validateCarrierSlugs($connection, $payload);
        return $this->present($this->persist($connection, $connector->updateShippingProfile($connection, $id, $payload)));
    }

    private function validateCarrierSlugs(MarketplaceConnection $connection, array $payload): void
    {
        $destinations = $payload['destinations'] ?? [];
        if (! is_array($destinations) || $destinations === []) {
            throw new BusinessRuleException('Add at least one shipping destination.', 422);
        }

        $availableSlugs = MarketplaceShippingCarrier::where(
            'marketplace_connection_id',
            $connection->id
        )->pluck('slug')->all();

        if ($availableSlugs === []) {
            throw new BusinessRuleException(
                'Synchronize shipping profiles before selecting a carrier.',
                422
            );
        }

        foreach ($destinations as $destination) {
            $slug = is_array($destination) ? (string) ($destination['carrier'] ?? '') : '';
            if ($slug === '' || ! in_array($slug, $availableSlugs, true)) {
                throw new BusinessRuleException('Select a carrier provided by Refurbed.', 422);
            }
        }
    }

    private function syncCarriers(RefurbedConnector $connector, MarketplaceConnection $connection): array
    {
        $carriers = $connector->listAvailableCarriers($connection);
        $seen = [];

        foreach ($carriers as $carrier) {
            if (! is_array($carrier) || ! isset($carrier['name'], $carrier['slug'])) {
                continue;
            }

            $slug = (string) $carrier['slug'];
            $seen[] = $slug;

            MarketplaceShippingCarrier::updateOrCreate([
                'marketplace_connection_id' => $connection->id,
                'slug' => $slug,
            ], [
                'name' => (string) $carrier['name'],
                'raw_payload' => $carrier,
                'last_synced_at' => now(),
            ]);
        }

        MarketplaceShippingCarrier::where('marketplace_connection_id', $connection->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('slug', $seen))
            ->when($seen === [], fn ($query) => $query)
            ->delete();

        return MarketplaceShippingCarrier::where('marketplace_connection_id', $connection->id)
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn ($carrier) => [
                'name' => $carrier->name,
                'slug' => $carrier->slug,
            ])
            ->all();
    }

    public function delete(User $user, string $id): void
    {
        [$connection, $connector] = $this->refurbed($user);
        $profile = $this->owned($connection, $id);
        if (! $profile->is_deletable || $profile->num_offers_assigned > 0) throw new BusinessRuleException('Refurbed does not allow this shipping profile to be deleted. Move all assigned offers first.', 422);
        $connector->deleteShippingProfile($connection, $id);
        $profile->delete();
    }

    private function persist(MarketplaceConnection $connection, array $profile): MarketplaceShippingProfile
    {
        $id = (string) ($profile['id'] ?? data_get($profile, 'identifier.id', ''));
        if ($id === '') throw new BusinessRuleException('Refurbed returned a shipping profile without an ID.', 502);
        $stored = MarketplaceShippingProfile::updateOrCreate(['marketplace_connection_id' => $connection->id, 'external_id' => $id], [
            'version' => $profile['version'] ?? null,
            'is_current' => (bool) ($profile['is_current'] ?? true),
            'is_deletable' => (bool) ($profile['is_deletable'] ?? false),
            'name' => $profile['name'] ?? null,
            'source_country_code' => $profile['source_country_market_code'] ?? null,
            'source_country_market_name' => $profile['source_country_market_name'] ?? null,
            'num_offers_assigned' => (int) ($profile['num_offers_assigned'] ?? 0),
            'marketplace_created_at' => $profile['created_at'] ?? null,
            'raw_payload' => $profile,
            'last_synced_at' => now(),
        ]);
        $marketCodes = [];
        foreach (($profile['destinations'] ?? []) as $destination) {
            if (! is_array($destination) || empty($destination['market_code'])) continue;
            $marketCodes[] = (string) $destination['market_code'];
            MarketplaceShippingProfileDestination::updateOrCreate([
                'marketplace_shipping_profile_id' => $stored->id,
                'market_code' => (string) $destination['market_code'],
            ], [
                'market_name' => $destination['market_name'] ?? null,
                'is_site_market' => (bool) ($destination['is_site_market'] ?? false),
                'currency_code' => $destination['currency_code'] ?? null,
                'shipping_costs' => $destination['shipping_costs'] ?? null,
                'site_market_currency_code' => $destination['site_market_currency_code'] ?? null,
                'site_market_shipping_costs' => $destination['site_market_shipping_costs'] ?? null,
                'min_delivery_days' => $destination['min_delivery_days'] ?? null,
                'max_delivery_days' => $destination['max_delivery_days'] ?? null,
                'raw_payload' => $destination,
            ]);
        }
        MarketplaceShippingProfileDestination::where('marketplace_shipping_profile_id', $stored->id)
            ->when($marketCodes !== [], fn ($query) => $query->whereNotIn('market_code', $marketCodes))
            ->when($marketCodes === [], fn ($query) => $query)
            ->delete();
        return $stored->load('destinations');
    }

    private function present(MarketplaceShippingProfile $profile): array
    {
        return ['id' => $profile->external_id, 'version' => $profile->version, 'is_current' => $profile->is_current, 'is_deletable' => $profile->is_deletable, 'name' => $profile->name, 'source_country_code' => $profile->source_country_code, 'source_country_market_name' => $profile->source_country_market_name, 'num_offers_assigned' => $profile->num_offers_assigned, 'created_at' => $profile->marketplace_created_at?->toISOString(), 'destinations' => $profile->destinations->map->only(['market_code', 'market_name', 'is_site_market', 'currency_code', 'shipping_costs', 'site_market_currency_code', 'site_market_shipping_costs', 'min_delivery_days', 'max_delivery_days'])->all(), 'details' => $profile->raw_payload, 'last_synced_at' => $profile->last_synced_at?->toISOString()];
    }

    private function owned(MarketplaceConnection $connection, string $id): MarketplaceShippingProfile { return MarketplaceShippingProfile::where('marketplace_connection_id', $connection->id)->where('external_id', $id)->firstOrFail(); }

    private function refurbed(User $user): array
    {
        $connection = MarketplaceConnection::whereBelongsTo($user)->where('status', MarketplaceConnectionStatus::Connected->value)->whereHas('marketplace', fn ($q) => $q->where('slug', 'refurbed'))->with('marketplace')->first();
        if (! $connection) throw new BusinessRuleException('Connect Refurbed before managing shipping profiles.', 422);
        $connector = $this->connectors->connector($connection->marketplace);
        if (! $connector instanceof RefurbedConnector) throw new BusinessRuleException('Shipping profiles require the real Refurbed connection.', 422);
        return [$connection, $connector];
    }

}
