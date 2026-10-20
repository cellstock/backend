<?php

namespace App\Services\Marketplaces\Connectors;

use App\Contracts\Marketplaces\MarketplaceConnector;
use App\Exceptions\RefurbedApiException;
use App\Models\Marketplace;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceOrder;
use App\Services\Marketplaces\Sync\RefurbedOrderSynchronizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class RefurbedConnector implements MarketplaceConnector
{
    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly RefurbedOrderSynchronizer $orderSynchronizer
    ) {}

    public function credentialRules(): array
    {
        return [
            'api_key' => [
                'required',
                'string',
                'min:20',
                'max:2000',
            ],
        ];
    }

    public function testConnection(array $credentials): array
    {
        $token = $this->apiToken($credentials);
        $markets = $this->listMarkets($credentials);

        return [
            'external_account_id' => 'refurbed-'.$this->tokenFingerprint(
                $token
            ),

            'account_name' => 'Refurbed Seller Account',

            'metadata' => [
            'available_markets' => count($markets),

            'active_markets' => count(
                $markets
            ),
            ],
        ];
    }

    public function listMarkets(array $credentials): array
    {
        $markets = [];
        $startingAfter = null;

        do {
            $pagination = ['limit' => 100];

            if ($startingAfter !== null) {
                $pagination['starting_after'] = $startingAfter;
            }

            $response = $this->post($credentials,
                '/refb.merchant.v1.MarketService/ListMarkets',
                [
                    'pagination' => $pagination,
                    'sort' => ['by' => 'CODE', 'order' => 'ASC'],
                    'filter' => ['is_site' => ['value' => true]],
                ]
            );

            $payload = $this->normalizeResponse($response->json());
            $page = $payload['markets'] ?? null;

            if (! is_array($page)) {
                throw new RuntimeException(
                    'Refurbed returned an unexpected markets response.'
                );
            }

            foreach ($page as $market) {
                if (is_array($market) && isset($market['code'])) {
                    $markets[] = [
                        'code' => strtoupper((string) $market['code']),
                        'name' => (string) ($market['name'] ?? $market['code']),
                        'currency_code' => $market['currency_code'] ?? null,
                    ];
                }
            }

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $lastMarket = end($page);
            $startingAfter = $hasMore && is_array($lastMarket)
                ? (string) ($lastMarket['code'] ?? '')
                : null;

            if ($hasMore && $startingAfter === '') {
                throw new RuntimeException('Refurbed market pagination cursor is missing.');
            }
        } while ($hasMore);

        return $markets;
    }

    public function listOffers(
        MarketplaceConnection $connection,
        ?string $startingAfter = null,
        int $limit = 50
    ): array {
        $pagination = ['limit' => min(max($limit, 1), 100)];

        if ($startingAfter) {
            $pagination['starting_after'] = $startingAfter;
        }

        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OfferService/ListOffers', [
                'pagination' => $pagination,
                'sort' => ['by' => 'CREATED_AT', 'order' => 'DESC'],
            ]
        );

        $payload = $this->normalizeResponse($response->json());

        return [
            'offers' => is_array($payload['offers'] ?? null) ? $payload['offers'] : [],
            'has_more' => (bool) ($payload['has_more'] ?? false),
            'next_cursor' => is_array($payload['offers'] ?? null) && $payload['offers'] !== []
                ? (string) ($payload['offers'][array_key_last($payload['offers'])]['id'] ?? '')
                : null,
        ];
    }

    public function getInstance(
        MarketplaceConnection $connection,
        string $identifier,
        string $identifierType
    ): array {
        $identifier = trim($identifier);

        if (! in_array($identifierType, ['gtin', 'id', 'mpn'], true)) {
            throw new RuntimeException('Unsupported Refurbed instance identifier type.');
        }

        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.InstanceService/GetInstance',
            [$identifierType => $identifier]
        );

        $payload = $this->normalizeResponse($response->json());
        $instance = $payload['instance'] ?? null;

        if (! is_array($instance)) {
            throw new RuntimeException('Refurbed did not return an instance.');
        }

        return $instance;
    }

    public function createOffer(MarketplaceConnection $connection, array $data): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OfferService/CreateOffer',
            $data
        );

        $payload = $this->normalizeResponse($response->json());
        $offer = $payload['offer'] ?? null;

        if (! is_array($offer)) {
            throw new RuntimeException('Refurbed did not return the created offer.');
        }

        return $offer;
    }

    public function updateOffer(MarketplaceConnection $connection, string $offerId, array $data): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OfferService/UpdateOffer',
            ['identifier' => ['id' => $offerId], ...$data]
        );
        $payload = $this->normalizeResponse($response->json());

        if (! is_array($payload['offer'] ?? null)) {
            throw new RuntimeException('Refurbed did not return the updated offer.');
        }

        return $payload['offer'];
    }

    public function deleteOffer(MarketplaceConnection $connection, string $offerId): void
    {
        $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OfferService/DeleteOffer',
            ['identifier' => ['id' => $offerId]]
        );
    }

    public function listShippingProfiles(MarketplaceConnection $connection, ?string $startingAfter = null): array
    {
        $pagination = ['limit' => 100];
        if ($startingAfter) {
            $pagination['starting_after'] = $startingAfter;
        }
        $response = $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/ListShippingProfiles', ['pagination' => $pagination]);
        $payload = $this->normalizeResponse($response->json());
        $profiles = is_array($payload['shipping_profiles'] ?? null) ? $payload['shipping_profiles'] : [];
        $last = $profiles === [] ? null : $profiles[array_key_last($profiles)];

        return ['profiles' => $profiles, 'has_more' => (bool) ($payload['has_more'] ?? false), 'next_cursor' => is_array($last) ? (string) ($last['id'] ?? '') : null];
    }

    public function getShippingProfile(MarketplaceConnection $connection, string $profileId): array
    {
        $response = $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/GetShippingProfile', ['id' => $profileId]);
        $payload = $this->normalizeResponse($response->json());

        return $this->shippingProfileFrom($payload);
    }

    public function createShippingProfile(MarketplaceConnection $connection, array $profile): array
    {
        $response = $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/CreateShippingProfile', $profile);

        return $this->shippingProfileFrom($this->normalizeResponse($response->json()));
    }

    public function updateShippingProfile(MarketplaceConnection $connection, string $profileId, array $profile): array
    {
        $response = $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/UpdateShippingProfile', ['id' => $profileId, ...$profile]);

        return $this->shippingProfileFrom($this->normalizeResponse($response->json()));
    }

    public function deleteShippingProfile(MarketplaceConnection $connection, string $profileId): void
    {
        $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/DeleteShippingProfile', ['id' => $profileId]);
    }

    public function listAvailableCarriers(MarketplaceConnection $connection): array
    {
        $response = $this->post($connection->credentials, '/refb.merchant.v1.ShippingProfileService/ListAvailableCarriers', ['pagination' => ['limit' => 100]]);
        $payload = $this->normalizeResponse($response->json());

        return is_array($payload['carriers'] ?? null) ? $payload['carriers'] : [];
    }

    private function shippingProfileFrom(array $payload): array
    {
        $profile = $payload['shipping_profile'] ?? $payload['profile'] ?? null;
        if (! is_array($profile)) {
            throw new RuntimeException('Refurbed did not return a shipping profile.');
        }

        return $profile;
    }

    public function updateOfferStocks(MarketplaceConnection $connection, array $stocks): array
    {
        $results = [];

        foreach (array_chunk($stocks, 50, true) as $chunk) {
            $offers = [];
            foreach ($chunk as $sku => $stock) {
                $offers[] = [
                    'identifier' => ['sku' => (string) $sku],
                    'stock' => (int) $stock,
                ];
            }

            $response = $this->post(
                $connection->credentials,
                '/refb.merchant.v1.OfferService/BatchUpdateOffers',
                ['offers' => $offers]
            );
            $payload = $this->normalizeResponse($response->json());
            $batchResults = is_array($payload['results'] ?? null) ? $payload['results'] : [];

            foreach (array_keys($chunk) as $index => $sku) {
                $result = is_array($batchResults[$index] ?? null)
                    ? $batchResults[$index]
                    : [
                        'status' => [
                            'code' => 13,
                            'message' => 'Refurbed did not return a result for this update.',
                        ],
                    ];
                $result['sku'] = (string) $sku;
                $results[] = $result;
            }
        }

        return $results;
    }

    public function sync(
        MarketplaceConnection $connection,
        string $type,
        array $settings = []
    ): array {
        if (! in_array($type, ['full', 'orders'], true)) {
            throw new RuntimeException(
                "Refurbed does not currently support {$type} synchronization."
            );
        }

        if (($settings['sync_orders'] ?? true) === false) {
            return [
                'processed_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
            ];
        }

        $marketFilter = $this->orderMarketFilter($settings);

        if (($marketFilter['shipping_address_country_code']['any_of'] ?? null) === []) {
            return [
                'processed_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
            ];
        }

        $credentials = is_array($connection->credentials)
            ? $connection->credentials
            : [];

        $this->apiToken($credentials);

        $pageSize = min(
            max(
                (int) ($settings['orders_page_size'] ?? 100),
                1
            ),
            100
        );

        $processedCount = 0;
        $successCount = 0;
        $failedCount = 0;
        $startingAfter = null;
        $previousCursor = null;

        do {
            $pagination = [
                'limit' => $pageSize,
            ];

            if ($startingAfter !== null) {
                $pagination['starting_after'] = $startingAfter;
            }

            $response = $this->post(
                $credentials,
                '/refb.merchant.v1.OrderService/ListOrders',
                array_filter([
                    'pagination' => $pagination,
                    'sort' => [
                        'by' => 'ID',
                        'order' => 'ASC',
                    ],
                    'filter' => $marketFilter,
                ])
            );

            $payload = $this->normalizeResponse(
                $response->json()
            );

            $orders = $payload['orders'] ?? [];

            if (! is_array($orders)) {
                throw new RuntimeException(
                    'Refurbed returned an invalid orders collection.'
                );
            }

            foreach ($orders as $orderData) {
                if (! is_array($orderData)) {
                    $processedCount++;
                    $failedCount++;

                    continue;
                }

                $processedCount++;

                try {
                    $this->orderSynchronizer->synchronize(
                        $connection,
                        $orderData
                    );

                    $successCount++;
                } catch (Throwable $exception) {
                    $failedCount++;

                    Log::warning(
                        'A Refurbed order could not be synchronized.',
                        [
                            'marketplace_connection_id' => $connection->id,

                            'external_order_id' => $orderData['id'] ?? null,

                            'exception' => $exception::class,
                        ]
                    );
                }
            }

            $hasMore = (bool) ($payload['has_more'] ?? false);

            if (! $hasMore) {
                break;
            }

            $this->pauseWhenRateLimitIsExhausted($response);

            $lastOrder = end($orders);

            if (
                $orders === []
                || ! is_array($lastOrder)
                || ! isset($lastOrder['id'])
                || (string) $lastOrder['id'] === ''
            ) {
                throw new RuntimeException(
                    'Refurbed indicated more orders but did not return a pagination cursor.'
                );
            }

            $startingAfter = (string) $lastOrder['id'];

            if ($startingAfter === $previousCursor) {
                throw new RuntimeException(
                    'Refurbed returned the same pagination cursor repeatedly.'
                );
            }

            $previousCursor = $startingAfter;
        } while (true);

        return [
            'processed_count' => $processedCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ];
    }

    public function updateOrderStatus(
        MarketplaceOrder $order,
        string $status,
        ?string $trackingUrl = null,
        array $itemIdentifiers = [],
    ): MarketplaceOrder {
        $order->loadMissing(['connection.marketplace', 'items']);

        if ($order->items->isEmpty()) {
            throw new RuntimeException('The order has no items to update.');
        }

        if ($order->items->count() > 50) {
            throw new RuntimeException('Refurbed supports at most 50 item state updates in one atomic order action.');
        }

        $transitions = [
            'NEW' => ['ACCEPTED', 'REJECTED', 'CANCELLED'],
            'ACCEPTED' => ['SHIPPED', 'CANCELLED'],
            'SHIPPED' => ['RETURNED'],
        ];

        foreach ($order->items as $item) {
            $allowed = $transitions[strtoupper((string) $item->status)] ?? [];

            if (! in_array($status, $allowed, true)) {
                throw new RuntimeException("The transition from {$item->status} to {$status} is not allowed by Refurbed.");
            }
        }

        $identifiersByItem = collect($itemIdentifiers)->keyBy(fn ($entry) => (string) ($entry['id'] ?? ''));
        $updates = $order->items->map(function ($item) use ($status, $trackingUrl, $identifiersByItem): array {
            $update = [
                'id' => (string) $item->external_order_item_id,
                'state' => $status,
            ];

            if ($status === 'SHIPPED') {
                $update['parcel_tracking_url'] = $trackingUrl;
            }

            $identifier = $identifiersByItem->get((string) $item->external_order_item_id);
            if (is_array($identifier) && ! empty($identifier['value'])) {
                $update['item_identifiers'] = [[
                    'identifier_type' => $identifier['identifier_type'],
                    'value' => $identifier['value'],
                ]];
            }

            return $update;
        })->all();

        $updatedItems = [];
        $failures = [];

        foreach (array_chunk($updates, 50) as $chunk) {
            $response = $this->post(
                $order->connection->credentials,
                '/refb.merchant.v1.OrderItemService/BatchUpdateOrderItemsState',
                ['updates' => $chunk]
            );

            $payload = $this->normalizeResponse($response->json());
            $results = $payload['results'] ?? null;

            if (! is_array($results) || count($results) !== count($chunk)) {
                throw new RuntimeException('Refurbed returned an incomplete status update response.');
            }

            foreach ($results as $index => $result) {
                $code = (int) data_get($result, 'status.code', 0);

                if ($code !== 0 || ! is_array($result['order_item'] ?? null)) {
                    $message = (string) data_get($result, 'status.message', 'Refurbed rejected the status update.');
                    $failures[] = $this->apiException(
                        $code,
                        $message,
                        data_get($result, 'status.details', [])
                    );

                    continue;
                }

                $updatedItems[(string) $chunk[$index]['id']] = $result['order_item'];
            }
        }

        DB::transaction(function () use ($order, $status, $trackingUrl, $updatedItems, $failures): void {
            foreach ($order->items as $item) {
                $remoteItem = $updatedItems[(string) $item->external_order_item_id] ?? null;

                if (! is_array($remoteItem)) {
                    continue;
                }

                $item->update([
                    'status' => $remoteItem['state'] ?? $status,
                    'raw_payload' => array_replace($item->raw_payload ?? [], $remoteItem),
                ]);
            }

            $rawPayload = $order->raw_payload ?? [];
            if ($failures === []) {
                $rawPayload['state'] = $status;
            }

            $order->update([
                'status' => $failures === [] ? $status : $order->status,
                'tracking_url' => $updatedItems !== [] && $trackingUrl
                    ? $trackingUrl
                    : $order->tracking_url,
                'shipped_at' => $updatedItems !== [] && $status === 'SHIPPED'
                    ? now()
                    : $order->shipped_at,
                'modified_at' => now(),
                'last_synced_at' => now(),
                'raw_payload' => $rawPayload,
            ]);
        });

        if ($failures !== []) {
            $failure = $failures[0];
            $prefix = $updatedItems !== []
                ? count($updatedItems).' order item(s) were updated, but another item failed. '
                : '';

            throw new RefurbedApiException(
                $prefix.$failure->getMessage(),
                $failure->grpcCode(),
                $failure->httpStatus(),
                $failure->details(),
            );
        }

        return $order->fresh(['connection.marketplace', 'items']);
    }

    public function listOrderItemReturns(MarketplaceOrder $order): array
    {
        return $this->listOrderItemReturnsForConnection(
            $order->connection,
            (string) $order->external_order_id,
        );
    }

    public function listOrderItemReturnsForConnection(
        MarketplaceConnection $connection,
        ?string $orderId = null,
    ): array {
        $request = [
            'pagination' => ['limit' => 100],
            'sort' => ['order' => 'DESC', 'by' => 'RETURN_INITIATED_AT'],
        ];

        if ($orderId !== null) {
            $request['filter'] = [
                'order_id' => ['any_of' => [$orderId]],
            ];
        }

        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OrderItemReturnService/ListOrderItemReturns',
            $request
        );

        $payload = $this->normalizeResponse($response->json());

        return is_array($payload['returns'] ?? null) ? $payload['returns'] : [];
    }

    public function calculateOrderRefund(MarketplaceOrder $order): array
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/CalculateRefundOrder',
            ['id' => (string) $order->external_order_id]
        );

        return $this->normalizeResponse($response->json());
    }

    public function refundOrder(MarketplaceOrder $order): array
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/RefundOrder',
            ['id' => (string) $order->external_order_id]
        );

        return $this->normalizeResponse($response->json());
    }

    public function calculateOrderItemRefund(
        MarketplaceOrder $order,
        string $orderItemId,
        string $targetPaidAmount,
    ): array {
        return $this->orderItemRefundRequest(
            $order,
            $orderItemId,
            $targetPaidAmount,
            'CalculateRefundOrderItem'
        );
    }

    public function refundOrderItem(
        MarketplaceOrder $order,
        string $orderItemId,
        string $targetPaidAmount,
    ): array {
        return $this->orderItemRefundRequest(
            $order,
            $orderItemId,
            $targetPaidAmount,
            'RefundOrderItem'
        );
    }

    private function orderItemRefundRequest(
        MarketplaceOrder $order,
        string $orderItemId,
        string $targetPaidAmount,
        string $operation,
    ): array {
        $response = $this->post(
            $order->connection->credentials,
            "/refb.merchant.v1.OrderItemService/{$operation}",
            [
                'id' => $orderItemId,
                'currency_code' => strtoupper((string) $order->currency),
                'target_paid_amount' => $targetPaidAmount,
            ]
        );

        return $this->normalizeResponse($response->json());
    }

    public function refreshOrder(MarketplaceOrder $order): MarketplaceOrder
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/GetOrder',
            ['id' => (string) $order->external_order_id]
        );
        $payload = $this->normalizeResponse($response->json());

        if (! is_array($payload['order'] ?? null)) {
            throw new RuntimeException('Refurbed did not return the requested order.');
        }

        return $this->orderSynchronizer->synchronize($order->connection, $payload['order'])
            ->load('connection.marketplace', 'items');
    }

    public function getOrder(MarketplaceConnection $connection, string $orderId): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.OrderService/GetOrder',
            ['id' => $orderId]
        );
        $payload = $this->normalizeResponse($response->json());
        $source = $payload['order'] ?? null;

        if (! is_array($source)) {
            throw new RuntimeException('Refurbed did not return the requested order.');
        }

        return $source;
    }

    public function getOrderInvoiceUrl(MarketplaceOrder $order): string
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/GetOrderInvoice',
            ['order_id' => (string) $order->external_order_id]
        );
        $url = data_get($this->normalizeResponse($response->json()), 'url');

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Refurbed did not return a valid invoice URL.');
        }

        return $url;
    }

    public function updateShippingLabelAddress(MarketplaceOrder $order, array $address): array
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/UpdateShippingLabelAddress',
            ['order_id' => (string) $order->external_order_id, 'address' => $address]
        );

        return $this->normalizeResponse($response->json());
    }

    public function createShippingLabel(MarketplaceOrder $order, array $labelData): array
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/CreateShippingLabel',
            [
                'order_id' => (string) $order->external_order_id,
                'merchant_address_id' => $labelData['merchant_address_id'],
                'parcel_weight' => $labelData['parcel_weight'],
                'carrier' => $this->shippingLabelCarrier($labelData['carrier']),
            ]
        );

        $payload = $this->normalizeResponse($response->json());
        $shippingLabel = $payload['shipping_label']
            ?? data_get($payload, '0.shipping_label');

        if (! is_array($shippingLabel)) {
            throw new RuntimeException('Refurbed did not return a shipping label.');
        }

        return $shippingLabel;
    }

    public function listMerchantAddresses(MarketplaceOrder $order): array
    {
        return $this->listMerchantAddressesForConnection($order->connection);
    }

    public function listMerchantAddressesForConnection(MarketplaceConnection $connection): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.MerchantService/ListMerchantAddresses',
            []
        );

        $payload = $this->normalizeResponse($response->json());

        return is_array($payload['merchant_addresses'] ?? null)
            ? $payload['merchant_addresses']
            : [];
    }

    public function createMerchantAddress(MarketplaceConnection $connection, array $address): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.MerchantService/CreateMerchantAddress',
            $address
        );

        return (array) data_get($this->normalizeResponse($response->json()), 'merchant_address', []);
    }

    public function updateMerchantAddress(MarketplaceConnection $connection, string $id, array $address): array
    {
        $response = $this->post(
            $connection->credentials,
            '/refb.merchant.v1.MerchantService/UpdateMerchantAddress',
            ['id' => $id, ...$address]
        );

        return (array) data_get($this->normalizeResponse($response->json()), 'merchant_address', []);
    }

    public function deleteMerchantAddress(MarketplaceConnection $connection, string $id): void
    {
        $this->post(
            $connection->credentials,
            '/refb.merchant.v1.MerchantService/DeleteMerchantAddress',
            ['id' => $id]
        );
    }

    private function shippingLabelCarrier(string $carrier): string
    {
        return match ($carrier) {
            'ups' => 'UPS',
            'dhl-express' => 'DHL_EXPRESS',
            default => 'UNSPECIFIED',
        };
    }

    public function uploadOrderInvoice(MarketplaceOrder $order, string $base64Data): array
    {
        $response = $this->post(
            $order->connection->credentials,
            '/refb.merchant.v1.OrderService/SetOrderInvoiceStream',
            [
                ['meta' => ['order_id' => (string) $order->external_order_id]],
                ['data' => $base64Data],
            ]
        );

        return $this->normalizeResponse($response->json());
    }

    private function orderMarketFilter(array $settings): ?array
    {
        $configuredMarkets = $settings['markets'] ?? [];

        if (! is_array($configuredMarkets) || $configuredMarkets === []) {
            return null;
        }

        $codes = array_values(array_filter(array_map(
            fn (mixed $market): ?string => is_array($market)
                && ($market['manage_orders'] ?? false)
                && ! empty($market['code'])
                    ? (string) $market['code']
                    : null,
            $configuredMarkets
        )));

        return [
            'shipping_address_country_code' => [
                'any_of' => $codes,
            ],
        ];
    }

    private function normalizeResponse(
        mixed $payload
    ): array {
        if (
            is_array($payload)
            && array_is_list($payload)
        ) {
            $payload = $payload[0] ?? [];
        }

        if (! is_array($payload)) {
            throw new RuntimeException(
                'Refurbed returned an unexpected response.'
            );
        }

        return $payload;
    }

    private function post(array $credentials, string $path, array $data): Response
    {
        // Empty protobuf messages must be JSON objects, not arrays.
        $client = $this->client($credentials);
        $response = $data === []
            ? $client->withBody('{}', 'application/json')->post($path)
            : $client->post($path, $data);

        if ($response->successful()) {
            return $response;
        }

        $payload = $response->json();
        $code = is_array($payload) ? (int) ($payload['code'] ?? 13) : 13;
        $message = is_array($payload)
            ? (string) ($payload['message'] ?? 'Refurbed rejected the request.')
            : $this->plainTextError($response);
        $details = is_array($payload) && is_array($payload['details'] ?? null)
            ? $payload['details']
            : [];

        if (! is_array($payload)) {
            $details = [
                'http_status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ];
        }

        throw $this->apiException($code, $message, $details, $response->status());
    }

    private function plainTextError(Response $response): string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($response->body())) ?? '');

        if ($message === '') {
            return 'Request failed with HTTP status '.$response->status().'.';
        }

        return mb_strimwidth($message, 0, 500, '...');
    }

    private function apiException(
        int $code,
        string $message,
        mixed $details = [],
        ?int $responseStatus = null
    ): RefurbedApiException {
        $normalized = strtolower(trim($message));
        $friendlyMessage = match (true) {
            $code === 16 => 'Refurbed authentication failed. Reconnect the marketplace with a valid token.',
            $code === 6 && str_contains($normalized, 'offer') => 'An offer with this SKU already exists on Refurbed. Update the existing offer instead.',
            $code === 5 && str_contains($normalized, 'order item') => 'The Refurbed order item was not found. Synchronize orders and try again.',
            $code === 5 && str_contains($normalized, 'offer') => 'The Refurbed offer was not found. Check its SKU or create it first.',
            $code === 3 && (str_contains($normalized, 'instance')
                || str_contains($normalized, 'gtin or id')
                || (str_contains($normalized, 'gtin') && str_contains($normalized, 'not found'))
                || (str_contains($normalized, 'unknown') && str_contains($normalized, 'instance')))
                => 'The product was not found on Refurbed. Use a recognized GTIN/EAN, or pick a catalog item that still exists on Refurbed.',
            $code === 5 && (str_contains($normalized, 'gtin or id')
                || (str_contains($normalized, 'gtin') && str_contains($normalized, 'not found'))
                || (str_contains($normalized, 'instance') && str_contains($normalized, 'not found')))
                => 'The product was not found on Refurbed. Use a recognized GTIN/EAN, or pick a catalog item that still exists on Refurbed.',
            $code === 3 && str_contains($normalized, 'shipping profile') => 'The shipping profile was not found on Refurbed.',
            $code === 3 && str_contains($normalized, 'not a number') => 'Refurbed prices must use numbers with a decimal point, for example 199.99.',
            $code === 3 && str_contains($normalized, 'tracking') => 'Refurbed requires a valid complete HTTP or HTTPS tracking URL.',
            $code === 9 && str_contains($normalized, 'state') => 'Refurbed does not allow this order status transition from the current item state.',
            $code === 8 => 'The Refurbed rate limit has been reached. Please try again after it resets.',
            default => $message !== '' ? 'Refurbed: '.$message : 'Refurbed rejected the request.',
        };

        $httpStatus = match ($code) {
            16 => 401,
            7 => 403,
            5 => 404,
            6 => 409,
            8 => 429,
            3, 9, 11 => 422,
            14 => 503,
            default => $responseStatus !== null && $responseStatus >= 400
                ? $responseStatus
                : 502,
        };

        return new RefurbedApiException(
            $friendlyMessage,
            $code,
            $httpStatus,
            is_array($details) ? $details : [],
        );
    }

    private function client(
        array $credentials
    ): PendingRequest {
        $token = $this->apiToken($credentials);

        $request = Http::baseUrl(
            $this->baseUrl()
        )
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Plain '.$token,
            ])
            ->timeout(
                config('services.refurbed.timeout', 30)
            )
            ->retry(
                config(
                    'services.refurbed.retry_times',
                    3
                ),
                fn (int $attempt, Throwable $exception): int => $this->retryDelay($attempt, $exception),
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
                throw: false
            );

        $caBundle = config(
            'services.refurbed.ca_bundle'
        );

        if (is_string($caBundle) && $caBundle !== '') {
            if (! is_file($caBundle)) {
                throw new RuntimeException(
                    'The configured Refurbed CA bundle does not exist.'
                );
            }

            $request->withOptions([
                'verify' => $caBundle,
            ]);
        }

        return $request;
    }

    private function apiToken(array $credentials): string
    {
        $token = $credentials['api_key'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException(
                'Refurbed API credentials are missing.'
            );
        }

        $token = trim($token);

        return preg_replace('/^Plain\s+/i', '', $token) ?: $token;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return in_array($status, [408, 425, 429], true)
            || $status >= 500;
    }

    private function retryDelay(
        int $attempt,
        Throwable $exception
    ): int {
        if ($exception instanceof RequestException) {
            $serverDelay = $this->serverDelay(
                $exception->response
            );

            if ($serverDelay !== null) {
                return $this->capDelay($serverDelay);
            }
        }

        $baseDelay = max(
            0,
            (int) config(
                'services.refurbed.retry_delay',
                500
            )
        );

        return $this->capDelay(
            $baseDelay * (2 ** max(0, $attempt - 1))
        );
    }

    private function pauseWhenRateLimitIsExhausted(
        Response $response
    ): void {
        if ((int) $response->header('X-RateLimit-Remaining') > 0) {
            return;
        }

        if ($response->header('X-RateLimit-Remaining') === null) {
            return;
        }

        $delay = $this->serverDelay($response);

        if ($delay !== null && $delay > 0) {
            Sleep::for($this->capDelay($delay))->milliseconds();
        }
    }

    private function serverDelay(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_string($retryAfter) && $retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                return max(0, (int) ceil((float) $retryAfter * 1000));
            }

            $retryAt = strtotime($retryAfter);

            if ($retryAt !== false) {
                return max(0, ($retryAt - time()) * 1000);
            }
        }

        $resetAt = $response->header('X-RateLimit-Reset');

        if (is_string($resetAt) && is_numeric($resetAt)) {
            return max(0, ((int) $resetAt - time()) * 1000);
        }

        return null;
    }

    private function capDelay(int $milliseconds): int
    {
        $maximum = max(
            0,
            (int) config(
                'services.refurbed.max_retry_delay',
                60000
            )
        );

        return min(max(0, $milliseconds), $maximum);
    }

    private function baseUrl(): string
    {
        $url = config(
            'services.refurbed.base_url'
        );

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                'Refurbed API URL is not configured.'
            );
        }

        return rtrim($url, '/');
    }

    private function tokenFingerprint(
        string $token
    ): string {
        return substr(
            hash('sha256', $token),
            0,
            12
        );
    }
}
