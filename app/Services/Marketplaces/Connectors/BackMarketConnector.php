<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Sync\BackMarketOrderSynchronizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use RuntimeException;

class BackMarketConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly BackMarketOrderSynchronizer $orderSynchronizer,
    ) {
    }

    public function credentialRules(): array
    {
        return [
            'access_token' => [
                'required',
                'string',
                'max:2000',
            ],

            'platform' => [
                'required',
                'string',
                Rule::in(['eu', 'na', 'ap']),
            ],

            'country_code' => [
                'required',
                'string',
                'regex:/^[a-z]{2}-[a-z]{2}$/',
            ],
        ];
    }

    public function testConnection(array $credentials): array
    {
        $this->client($credentials)
            ->get('/ws/listings', [
                'page' => 1,
                'page-size' => 1,
            ])
            ->throw();

        return [
            'external_account_id' =>
                'back-market-'.$credentials['platform'],

            'account_name' =>
                'Back Market '.strtoupper(
                    $credentials['platform']
                ),
        ];
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        return match ($type) {
            'orders' => $this->syncOrders($connection),

            'products',
            'inventory',
            'full' => throw new RuntimeException(
                "Real Back Market {$type} synchronization is not implemented yet."
            ),

            default => throw new RuntimeException(
                "Unsupported synchronization type: {$type}."
            ),
        };
    }

    private function syncOrders(
        MarketplaceConnection $connection
    ): array {
        $credentials = $connection->credentials;

        $totals = [
            'processed_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
        ];

        $page = 1;

        do {
            $response = $this->client($credentials)
                ->get('/ws/orders', [
                    'page' => $page,
                    'page-size' => 50,
                ])
                ->throw()
                ->json();

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

    private function client(
        array $credentials
    ): PendingRequest {
        return Http::baseUrl(
            $this->baseUrl($credentials['platform'])
        )
            ->acceptJson()
            ->withHeaders([
                'Authorization' =>
                    'Basic '.$credentials['access_token'],

                'Accept-Language' =>
                    $credentials['country_code'],

                'User-Agent' => config(
                    'services.back_market.user_agent'
                ),
            ])
            ->timeout(
                config('services.back_market.timeout', 30)
            )
            ->retry(
                config(
                    'services.back_market.retry_times',
                    3
                ),
                config(
                    'services.back_market.retry_delay',
                    500
                )
            );
    }

    private function baseUrl(string $platform): string
    {
        $url = config(
            "services.back_market.urls.production.{$platform}"
        );

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                "Back Market URL is not configured for {$platform}."
            );
        }

        return $url;
    }
}