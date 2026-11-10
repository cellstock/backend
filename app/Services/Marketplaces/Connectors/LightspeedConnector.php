<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Exceptions\BusinessRuleException;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceOrder;
use App\Services\Marketplaces\Sync\LightspeedListingSynchronizer;
use App\Services\Marketplaces\Sync\LightspeedOrderSynchronizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LightspeedConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly LightspeedListingSynchronizer $listingSynchronizer,
        private readonly LightspeedOrderSynchronizer $orderSynchronizer,
    ) {}

    public function credentialRules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'cluster' => ['sometimes', 'nullable', 'string', 'in:eu1,us1'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{api_key: string, api_secret: string, cluster: string, language: string}
     */
    public function normalizeCredentials(array $credentials): array
    {
        $cluster = strtolower(trim((string) (
            $credentials['cluster']
            ?? config('services.lightspeed.default_cluster', 'eu1')
        )));

        if (! in_array($cluster, ['eu1', 'us1'], true)) {
            $cluster = 'eu1';
        }

        $language = strtolower(trim((string) (
            $credentials['language']
            ?? config('services.lightspeed.default_language', 'nl')
        )));

        if ($language === '') {
            $language = 'nl';
        }

        return [
            'api_key' => trim((string) ($credentials['api_key'] ?? '')),
            'api_secret' => trim((string) ($credentials['api_secret'] ?? '')),
            'cluster' => $cluster,
            'language' => $language,
        ];
    }

    public function testConnection(array $credentials): array
    {
        $credentials = $this->normalizeCredentials($credentials);

        try {
            $response = $this->client($credentials)
                ->get('account.json')
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                $this->describeHttpError($exception),
                previous: $exception
            );
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'SSL certificate') || str_contains($message, 'cURL error 60')) {
                throw new RuntimeException(
                    'Lightspeed SSL verification failed. Ensure storage/certs/cacert.pem exists and LIGHTSPEED_CA_BUNDLE is set.',
                    previous: $exception
                );
            }

            throw new RuntimeException(
                'Lightspeed connection failed: '.$message,
                previous: $exception
            );
        }

        $account = is_array($response['account'] ?? null) ? $response['account'] : [];
        $shopId = (string) ($account['id'] ?? data_get($account, 'company.id') ?? 'unknown');
        $shopName = (string) (
            $account['companyName']
            ?? data_get($account, 'company.name')
            ?? 'Lightspeed shop'
        );

        return [
            'external_account_id' => 'lightspeed-'.$credentials['cluster'].'-'.$shopId,
            'account_name' => $shopName,
        ];
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        return match ($type) {
            'orders' => $this->syncIpadOrders($connection),
            'products', 'inventory' => $this->syncIpadListings($connection),
            // Products first so order filtering can use known iPad variant IDs.
            // Order sync is best-effort: Lightspeed 429s must not wipe a successful inventory pull.
            'full' => $this->syncFull($connection),
            default => throw new RuntimeException("Unsupported synchronization type: {$type}."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(MarketplaceConnection $connection, string $orderId): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials ?? []);
        $response = $this->getJson($credentials, 'orders/'.$orderId.'.json');
        $order = $response['order'] ?? $response;

        return is_array($order) ? $order : [];
    }

    /**
     * Push order status / tracking to Lightspeed (iPad-scoped orders already filtered on import).
     */
    public function updateOrderStatus(
        MarketplaceOrder $order,
        string $status,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
    ): MarketplaceOrder {
        $connection = $order->connection;
        $credentials = $this->normalizeCredentials($connection->credentials ?? []);
        $externalId = (string) $order->external_order_id;
        $status = strtoupper($status);

        $payload = match ($status) {
            'ACCEPTED', 'ACCEPT', 'CONFIRMED' => [
                'status' => 'processing_awaiting_shipment',
            ],
            'SHIPPED', 'SHIPPING', 'SENT' => [
                'status' => 'completed_shipped',
                'shipmentStatus' => 'shipped',
            ],
            'CANCELLED', 'CANCELED', 'REJECTED' => [
                'status' => 'cancelled',
            ],
            default => throw new BusinessRuleException(
                message: 'Unsupported Lightspeed status. Use accepted, shipped, or cancelled.',
                status: 422,
            ),
        };

        if (in_array($status, ['SHIPPED', 'SHIPPING', 'SENT'], true)) {
            $tracking = trim((string) ($trackingNumber ?: $order->tracking_number));
            if ($tracking === '' && $trackingUrl) {
                $tracking = trim($trackingUrl);
                if (filter_var($tracking, FILTER_VALIDATE_URL)) {
                    $path = trim((string) parse_url($tracking, PHP_URL_PATH), '/');
                    $parts = $path !== '' ? explode('/', $path) : [];
                    $tracking = $parts !== [] ? (string) end($parts) : $tracking;
                }
            }
            if ($tracking === '') {
                throw new BusinessRuleException(
                    message: 'A tracking number is required before marking a Lightspeed order as shipped.',
                    status: 422,
                );
            }

            $order->forceFill([
                'tracking_number' => $tracking,
                'tracking_url' => $trackingUrl ?: $order->tracking_url,
            ])->save();

            $this->updateShipmentTracking($credentials, $externalId, $tracking, $trackingUrl);
        }

        $this->client($credentials)
            ->asJson()
            ->put('orders/'.$externalId.'.json', ['order' => $payload])
            ->throw();

        $remote = $this->getOrder($connection, $externalId);
        $products = $this->fetchOrderProducts($credentials, (int) $externalId);
        $filter = $this->productTitleFilter($connection);
        $knownVariants = $this->knownIpadVariantIds($connection);
        $ipadProducts = array_values(array_filter(
            $products,
            fn (array $product): bool => $this->isIpadOrderProduct($product, $filter, $knownVariants)
        ));

        $this->orderSynchronizer->syncOrder($connection, $remote, $ipadProducts);

        return $order->fresh(['connection.marketplace', 'items']) ?? $order;
    }

    /**
     * Push stock levels for Lightspeed variant offers (iPad listings only).
     *
     * @param  list<array{variant_id: string, stock: int}>  $rows
     */
    public function updateVariantStocks(MarketplaceConnection $connection, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $credentials = $this->normalizeCredentials($connection->credentials ?? []);
        $updated = 0;

        foreach ($rows as $row) {
            $variantId = trim((string) ($row['variant_id'] ?? ''));
            if ($variantId === '') {
                continue;
            }

            $this->client($credentials)
                ->asJson()
                ->put('variants/'.$variantId.'.json', [
                    'variant' => [
                        'stockLevel' => max(0, (int) ($row['stock'] ?? 0)),
                    ],
                ])
                ->throw();

            $updated++;
        }

        return $updated;
    }

    /**
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    private function syncFull(MarketplaceConnection $connection): array
    {
        $listings = $this->syncIpadListings($connection);

        try {
            $orders = $this->syncIpadOrders($connection);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'processed_count' => $listings['processed_count'],
                'success_count' => $listings['success_count'],
                'failed_count' => $listings['failed_count'] + 1,
            ];
        }

        return $this->mergeTotals($listings, $orders);
    }

    /**
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    private function syncIpadOrders(MarketplaceConnection $connection): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials ?? []);
        $filter = $this->productTitleFilter($connection);
        $knownVariants = $this->knownIpadVariantIds($connection);
        $page = 1;
        $limit = 50;
        $payloads = [];
        // Avoid scanning the entire order history (Lightspeed rate-limits aggressively).
        $createdAtMin = now()->subDays(90)->format('Y-m-d H:i:s');

        do {
            $response = $this->getJson($credentials, 'orders.json', [
                'page' => $page,
                'limit' => $limit,
                'created_at_min' => $createdAtMin,
            ]);

            $orders = $response['orders'] ?? [];
            if (! is_array($orders)) {
                $orders = [];
            }

            foreach ($orders as $order) {
                if (! is_array($order)) {
                    continue;
                }

                $orderId = (int) ($order['id'] ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $products = $this->fetchOrderProducts($credentials, $orderId);
                $ipadProducts = array_values(array_filter(
                    $products,
                    fn (array $product): bool => $this->isIpadOrderProduct($product, $filter, $knownVariants)
                ));

                if ($ipadProducts === []) {
                    continue;
                }

                $payloads[] = [
                    'order' => $order,
                    'products' => $ipadProducts,
                ];
            }

            $fetched = count($orders);
            $page++;
        } while ($fetched >= $limit);

        return $this->orderSynchronizer->sync($connection, $payloads);
    }

    /**
     * @return array{processed_count: int, success_count: int, failed_count: int}
     */
    private function syncIpadListings(MarketplaceConnection $connection): array
    {
        $credentials = $this->normalizeCredentials($connection->credentials ?? []);
        $filter = $this->productTitleFilter($connection);
        $page = 1;
        $limit = 250;
        $listings = [];
        $seenVariantIds = [];

        do {
            $response = $this->getJson($credentials, 'products.json', [
                'page' => $page,
                'limit' => $limit,
            ]);

            $products = $response['products'] ?? [];
            if (! is_array($products)) {
                $products = [];
            }

            foreach ($products as $product) {
                if (! is_array($product) || ! $this->matchesProductFilter($product, $filter)) {
                    continue;
                }

                $productId = (int) ($product['id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                foreach ($this->fetchVariants($credentials, $productId) as $variant) {
                    if (! is_array($variant)) {
                        continue;
                    }

                    $variantId = (string) ($variant['id'] ?? '');
                    if ($variantId === '' || isset($seenVariantIds[$variantId])) {
                        continue;
                    }

                    $seenVariantIds[$variantId] = true;
                    $listings[] = [
                        'product' => $product,
                        'variant' => $variant,
                    ];
                }
            }

            $fetched = count($products);
            $page++;
        } while ($fetched >= $limit);

        $result = $this->listingSynchronizer->sync($connection, $listings);

        if ($seenVariantIds !== []) {
            MarketplaceOffer::query()
                ->where('marketplace_connection_id', $connection->id)
                ->where('pending_upload', false)
                ->whereNotIn('external_offer_id', array_keys($seenVariantIds))
                ->delete();
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchVariants(array $credentials, int $productId): array
    {
        $response = $this->getJson($credentials, 'variants.json', [
            'product' => $productId,
            'limit' => 250,
        ]);

        $variants = $response['variants'] ?? [];

        return is_array($variants) ? array_values(array_filter($variants, 'is_array')) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrderProducts(array $credentials, int $orderId): array
    {
        $response = $this->getJson($credentials, 'orders/'.$orderId.'/products.json', [
            'limit' => 250,
        ]);

        $products = $response['orderProducts'] ?? $response['products'] ?? [];

        return is_array($products) ? array_values(array_filter($products, 'is_array')) : [];
    }

    /**
     * @return array<string, true>
     */
    private function knownIpadVariantIds(MarketplaceConnection $connection): array
    {
        return MarketplaceOffer::query()
            ->where('marketplace_connection_id', $connection->id)
            ->pluck('external_offer_id')
            ->filter()
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, true>  $knownVariants
     */
    private function isIpadOrderProduct(array $product, string $filter, array $knownVariants): bool
    {
        $variantId = (string) data_get($product, 'variant.resource.id', '');
        if ($variantId !== '' && isset($knownVariants[$variantId])) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($product['productTitle'] ?? ''),
            (string) ($product['variantTitle'] ?? ''),
            (string) ($product['sku'] ?? ''),
            (string) ($product['articleCode'] ?? ''),
        ])));

        return $filter !== '' && str_contains($haystack, $filter);
    }

    private function updateShipmentTracking(
        array $credentials,
        string $orderId,
        string $trackingCode,
        ?string $trackingUrl = null,
    ): void {
        $response = $this->client($credentials)
            ->get('shipments.json', ['order' => $orderId])
            ->throw()
            ->json();

        $shipments = $response['shipments'] ?? [];
        if (! is_array($shipments) || $shipments === []) {
            // Lightspeed creates shipment objects during fulfilment; still allow order-level status update.
            return;
        }

        $shipment = $shipments[0];
        $shipmentId = (string) ($shipment['id'] ?? '');
        if ($shipmentId === '') {
            return;
        }

        $payload = [
            'status' => 'shipped',
            'trackingCode' => $trackingCode,
            'doNotifyShipped' => true,
            'doNotifyTrackingCode' => true,
        ];

        if ($trackingUrl) {
            $payload['trackingUrl'] = $trackingUrl;
        }

        $this->client($credentials)
            ->asJson()
            ->put('shipments/'.$shipmentId.'.json', ['shipment' => $payload])
            ->throw();
    }

    private function productTitleFilter(MarketplaceConnection $connection): string
    {
        $fromSettings = trim((string) data_get($connection->settings, 'product_title_filter', ''));
        if ($fromSettings !== '') {
            return mb_strtolower($fromSettings);
        }

        return mb_strtolower((string) config(
            'services.lightspeed.product_title_filter',
            'ipad'
        ));
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function matchesProductFilter(array $product, string $filter): bool
    {
        if ($filter === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($product['title'] ?? ''),
            (string) ($product['fulltitle'] ?? ''),
            (string) ($product['url'] ?? ''),
            (string) ($product['description'] ?? ''),
        ])));

        return str_contains($haystack, $filter);
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

    /**
     * Throttled GET that backs off on Lightspeed 429 rate limits.
     *
     * @param  array{api_key: string, api_secret: string, cluster: string, language: string}  $credentials
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(array $credentials, string $path, array $query = []): array
    {
        $maxAttempts = 8;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            usleep(200_000); // ~5 req/s soft cap to stay under Lightspeed limits

            $response = $this->client($credentials)->get($path, $query);

            if ($response->status() === 429) {
                if ($attempt === $maxAttempts) {
                    throw new RuntimeException(
                        'Lightspeed rate limit reached (HTTP 429). Wait a few minutes and sync again (prefer Products/Inventory, then Orders).'
                    );
                }

                $retryAfter = (int) ($response->header('Retry-After') ?: 0);
                sleep(max(15, min(120, $retryAfter > 0 ? $retryAfter : 20 * $attempt)));

                continue;
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    $this->describeHttpError(
                        new RequestException($response)
                    )
                );
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        throw new RuntimeException('Lightspeed request failed after retries.');
    }

    /**
     * @param  array{api_key: string, api_secret: string, cluster: string, language: string}  $credentials
     */
    private function client(array $credentials): PendingRequest
    {
        $baseUrl = rtrim($this->clusterBaseUrl($credentials['cluster']), '/');
        $language = trim($credentials['language'], '/');

        $request = Http::baseUrl($baseUrl.'/'.$language.'/')
            ->withBasicAuth($credentials['api_key'], $credentials['api_secret'])
            ->acceptJson()
            ->timeout((int) config('services.lightspeed.timeout', 30));

        $caBundle = config('services.lightspeed.ca_bundle');
        if (is_string($caBundle) && $caBundle !== '') {
            if (! is_file($caBundle)) {
                throw new RuntimeException(
                    'The configured Lightspeed CA bundle does not exist: '.$caBundle
                );
            }

            $request->withOptions([
                'verify' => $caBundle,
            ]);
        }

        return $request;
    }

    private function describeHttpError(RequestException $exception): string
    {
        $status = $exception->response?->status();
        $body = trim((string) ($exception->response?->body() ?? ''));
        $snippet = $body !== '' ? mb_strimwidth(strip_tags($body), 0, 200, '...') : '';

        return match ($status) {
            401, 403 => 'Lightspeed authentication failed. Check the API key, API secret, and that the key is enabled with account permissions.',
            404 => 'Lightspeed endpoint not found. Check cluster (eu1) and shop language (nl).',
            429 => 'Lightspeed rate limit reached. Wait a few minutes and try again.',
            default => 'Lightspeed API error'
                .($status ? " (HTTP {$status})" : '')
                .($snippet !== '' ? ': '.$snippet : '.'),
        };
    }

    private function clusterBaseUrl(string $cluster): string
    {
        $urls = config('services.lightspeed.clusters', []);
        $url = $urls[$cluster] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("Lightspeed cluster [{$cluster}] is not configured.");
        }

        return $url;
    }
}
