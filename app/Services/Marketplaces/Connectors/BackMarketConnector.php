<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Sync\BackMarketListingSynchronizer;
use App\Services\Marketplaces\Sync\BackMarketOrderSynchronizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BackMarketConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly BackMarketOrderSynchronizer $orderSynchronizer,
        private readonly BackMarketListingSynchronizer $listingSynchronizer,
    ) {}

    public function credentialRules(): array
    {
        return [
            'api_key'      => ['required_without:access_token', 'nullable', 'string', 'max:2000'],
            'access_token' => ['required_without:api_key',      'nullable', 'string', 'max:2000'],
            // platform and environment are derived server-side; accepted but not required
            'country_code' => ['required', 'string', 'regex:/^[a-z]{2}-[a-z]{2}$/'],
        ];
    }

    /**
     * Derive the Back Market platform from the marketplace slug.
     * Slugs are: back-market-eu | back-market-na | back-market-ap
     */
    private function platformFromSlug(): string
    {
        return match ($this->marketplace->slug) {
            'back-market-na' => 'na',
            'back-market-ap' => 'ap',
            default          => 'eu',   // back-market-eu and any legacy slug
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{access_token: string, platform: string, country_code: string, environment: string, api_key?: string}
     */
    public function normalizeCredentials(array $credentials): array
    {
        $token = trim((string) ($credentials['access_token'] ?? $credentials['api_key'] ?? ''));
        $token = preg_replace('/^Basic\s+/i', '', $token) ?: $token;

        return [
            'access_token' => $token,
            'api_key'      => $token,
            // Platform is always derived from the marketplace slug; ignore any
            // value the caller passes to prevent misconfiguration.
            'platform'     => $this->platformFromSlug(),
            'country_code' => strtolower((string) ($credentials['country_code'] ?? 'fr-fr')),
            // Environment comes from .env (BACK_MARKET_ENV); never stored in
            // the connection credentials so the admin can switch envs centrally.
            'environment'  => config('services.back_market.default_environment', 'preproduction'),
        ];
    }

    public function testConnection(array $credentials): array
    {
        $credentials = $this->normalizeCredentials($credentials);

        $this->client($credentials)
            ->get('/ws/listings', [
                'page' => 1,
                'page-size' => 1,
            ])
            ->throw();

        $envLabel = $credentials['environment'] === 'production' ? 'Prod' : 'Preprod';

        return [
            'external_account_id' => 'back-market-'.$credentials['platform'].'-'.$credentials['environment'],
            'account_name' => 'Back Market '.strtoupper($credentials['platform']).' ('.$envLabel.')',
        ];
    }

    /**
     * Return the available locale codes for this connector's platform.
     *
     * Back Market does not expose a "list locales" API endpoint so the list is
     * maintained here. Platform is derived from the marketplace slug.
     *
     * Each entry: { code, name, currency_code }
     *
     * @return list<array{code: string, name: string, currency_code: string}>
     */
    public function getLocales(): array
    {
        $platform = $this->platformFromSlug();

        $locales = [
            'eu' => [
                ['code' => 'fr-fr', 'name' => 'BackMarket FR', 'currency_code' => 'EUR'],
                ['code' => 'de-de', 'name' => 'BackMarket DE', 'currency_code' => 'EUR'],
                ['code' => 'es-es', 'name' => 'BackMarket ES', 'currency_code' => 'EUR'],
                ['code' => 'it-it', 'name' => 'BackMarket IT', 'currency_code' => 'EUR'],
                ['code' => 'nl-nl', 'name' => 'BackMarket NL', 'currency_code' => 'EUR'],
                ['code' => 'pt-pt', 'name' => 'BackMarket PT', 'currency_code' => 'EUR'],
                ['code' => 'en-gb', 'name' => 'BackMarket GB', 'currency_code' => 'GBP'],
                ['code' => 'sv-se', 'name' => 'BackMarket SE', 'currency_code' => 'SEK'],
                ['code' => 'fi-fi', 'name' => 'BackMarket FI', 'currency_code' => 'EUR'],
                ['code' => 'da-dk', 'name' => 'BackMarket DK', 'currency_code' => 'DKK'],
                ['code' => 'de-at', 'name' => 'BackMarket AT', 'currency_code' => 'EUR'],
                ['code' => 'nl-be', 'name' => 'BackMarket BE', 'currency_code' => 'EUR'],
                ['code' => 'el-gr', 'name' => 'BackMarket GR', 'currency_code' => 'EUR'],
                ['code' => 'en-ie', 'name' => 'BackMarket IE', 'currency_code' => 'EUR'],
                ['code' => 'sk-sk', 'name' => 'BackMarket SK', 'currency_code' => 'EUR'],
            ],
            'na' => [
                ['code' => 'en-us', 'name' => 'BackMarket US', 'currency_code' => 'USD'],
                ['code' => 'en-ca', 'name' => 'BackMarket CA', 'currency_code' => 'CAD'],
            ],
            'ap' => [
                ['code' => 'ja-jp', 'name' => 'BackMarket JP', 'currency_code' => 'JPY'],
                ['code' => 'en-au', 'name' => 'BackMarket AU', 'currency_code' => 'AUD'],
            ],
        ];

        return $locales[$platform] ?? [];
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        return match ($type) {
            'orders' => $this->syncOrders($connection),
            'products', 'inventory' => $this->syncListings($connection),
            'full' => $this->mergeTotals(
                $this->syncOrders($connection),
                $this->syncListings($connection),
            ),
            default => throw new RuntimeException("Unsupported synchronization type: {$type}."),
        };
    }

    /**
     * @return array{listings: list<array<string, mixed>>, has_more: bool, next_page: int|null}
     */
    public function listListings(MarketplaceConnection $connection, int $page = 1, int $pageSize = 50): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials);
        $response = $this->client($credentials)
            ->get('/ws/listings', [
                'page' => $page,
                'page-size' => min(50, max(1, $pageSize)),
            ])
            ->throw()
            ->json();

        $results = is_array($response['results'] ?? null) ? $response['results'] : [];

        return [
            'listings' => $results,
            'has_more' => ! empty($response['next']),
            'next_page' => ! empty($response['next']) ? $page + 1 : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateListing(MarketplaceConnection $connection, string $listingId, array $data): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials);
        $response = $this->client($credentials)
            ->post('/ws/listings/'.$listingId, $data)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * Create or update listings via Back Market CSV catalog upload.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return array{task_id: int|null, statuscode: int|null}
     */
    public function upsertListings(MarketplaceConnection $connection, array $rows): array
    {
        if ($rows === []) {
            throw new RuntimeException('At least one listing row is required.');
        }

        $headers = array_keys($rows[0]);
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($header) => $this->csvValue($row[$header] ?? ''),
                $headers
            ));
        }

        $credentials = $this->normalizeCredentials($connection->credentials);
        $response = $this->client($credentials)
            ->post('/ws/listings', [
                'catalog' => implode("\n", $lines),
                'quotechar' => '"',
                'delimiter' => ',',
                'encoding' => 'utf-8',
            ])
            ->throw()
            ->json();

        return [
            'task_id' => isset($response['bodymessage']) ? (int) $response['bodymessage'] : null,
            'statuscode' => isset($response['statuscode']) ? (int) $response['statuscode'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(MarketplaceConnection $connection, string $orderId): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials);
        $response = $this->client($credentials)
            ->get('/ws/orders/'.$orderId)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateOrder(MarketplaceConnection $connection, string $orderId, array $payload): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials);
        $body = array_merge(['order_id' => (int) $orderId], $payload);
        $response = $this->client($credentials)
            ->post('/ws/orders/'.$orderId, $body)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    private function syncOrders(MarketplaceConnection $connection): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials);
        $totals = ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0];
        $page = 1;

        do {
            try {
                $response = $this->client($credentials)
                    ->get('/ws/orders', [
                        'page' => $page,
                        'page-size' => 50,
                        'country_code' => $credentials['country_code'],
                    ])
                    ->throw()
                    ->json();
            } catch (RequestException $exception) {
                throw new RuntimeException(
                    'Back Market order sync failed: '.$this->errorMessage($exception),
                    previous: $exception
                );
            }

            $result = $this->orderSynchronizer->sync(
                connection: $connection,
                orders: $response['results'] ?? [],
            );

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }

            $page++;
        } while (! empty($response['next']));

        return $totals;
    }

    private function syncListings(MarketplaceConnection $connection): array
    {
        $totals = ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0];
        $page = 1;

        do {
            $pageResult = $this->listListings($connection, $page, 50);
            $result = $this->listingSynchronizer->sync($connection, $pageResult['listings']);
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }
            $page = $pageResult['next_page'] ?? null;
        } while ($page !== null);

        return $totals;
    }

    /**
     * @param  array{processed_count: int, success_count: int, failed_count: int}  ...$parts
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    private function mergeTotals(array ...$parts): array
    {
        $totals = ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0];
        foreach ($parts as $part) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($part[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function client(array $credentials): PendingRequest
    {
        $credentials = $this->normalizeCredentials($credentials);

        return Http::baseUrl($this->baseUrl($credentials['platform'], $credentials['environment']))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Basic '.$credentials['access_token'],
                'Accept-Language' => $credentials['country_code'],
                'User-Agent' => config('services.back_market.user_agent'),
            ])
            ->timeout(config('services.back_market.timeout', 30))
            ->retry(
                config('services.back_market.retry_times', 3),
                config('services.back_market.retry_delay', 500)
            );
    }

    private function baseUrl(string $platform, string $environment): string
    {
        $url = config("services.back_market.urls.{$environment}.{$platform}");

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                "Back Market URL is not configured for {$environment}/{$platform}."
            );
        }

        return $url;
    }

    private function csvValue(mixed $value): string
    {
        $string = (string) $value;
        if (str_contains($string, ',') || str_contains($string, '"') || str_contains($string, "\n")) {
            return '"'.str_replace('"', '""', $string).'"';
        }

        return $string;
    }

    private function errorMessage(RequestException $exception): string
    {
        $payload = $exception->response?->json();
        if (is_array($payload)) {
            $message = $payload['message'] ?? $payload['detail'] ?? $payload['error'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = trim((string) ($exception->response?->body() ?? ''));

        return $body !== '' ? mb_strimwidth(strip_tags($body), 0, 300, '...') : $exception->getMessage();
    }
}
