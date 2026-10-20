<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\RefurbedApiException;
use App\Models\MarketplaceMerchantAddress;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderReturn;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceShippingLabel;
use App\Models\User;
use App\Services\Marketplaces\Connectors\BackMarketConnector;
use App\Services\Marketplaces\Connectors\RefurbedConnector;
use App\Services\Marketplaces\MarketplaceConnectorManager;
use App\Services\Marketplaces\Sync\BackMarketOrderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrderService
{
    public function __construct(
        private readonly MarketplaceOrderVisibility $visibility,
        private readonly MarketplaceConnectorManager $connectorManager,
    ) {}

    public function paginateForUser(
        User $user,
        array $filters
    ): LengthAwarePaginator {
        $sortBy = $filters['sort_by'] ?? 'ordered_at';
        $sortDirection =
            $filters['sort_direction'] ?? 'desc';

        $query = MarketplaceOrder::query()
            ->with([
                'connection.marketplace',
                'items',
            ]);

        return $this->visibility->apply($query, $user)
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $search = addcslashes(
                        $search,
                        '%_\\'
                    );

                    $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'external_order_id',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'items',
                                    function ($query) use (
                                        $search
                                    ): void {
                                        $query
                                            ->where(
                                                'sku',
                                                'like',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'title',
                                                'like',
                                                "%{$search}%"
                                            );
                                    }
                                );
                        }
                    );
                }
            )
            ->when(
                $filters['marketplace'] ?? null,
                fn ($query, string $slug) => $query->whereHas(
                    'connection.marketplace',
                    fn ($query) => $query->where(
                        'slug',
                        $slug
                    )
                )
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->when(
                $filters['country_code'] ?? null,
                fn ($query, string $countryCode) => $query->where(
                    'country_code',
                    $countryCode
                )
            )
            ->when(
                $filters['date_from'] ?? null,
                fn ($query, string $date) => $query->where(
                    'ordered_at',
                    '>=',
                    CarbonImmutable::parse($date)
                        ->startOfDay()
                )
            )
            ->when(
                $filters['date_to'] ?? null,
                fn ($query, string $date) => $query->where(
                    'ordered_at',
                    '<=',
                    CarbonImmutable::parse($date)
                        ->endOfDay()
                )
            )
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    public function findForUser(
        User $user,
        int $orderId
    ): MarketplaceOrder {
        $query = MarketplaceOrder::query()
            ->with([
                'connection.marketplace',
                'items',
                'shippingLabels',
            ]);

        return $this->visibility->apply($query, $user)->findOrFail($orderId);
    }

    public function updateStatus(
        User $user,
        int $orderId,
        string $status,
        ?string $trackingUrl = null,
        array $itemIdentifiers = [],
    ): MarketplaceOrder {
        $order = $this->findForUser($user, $orderId);
        $slug = $order->connection->marketplace->slug;
        $connector = $this->connectorManager->connector($order->connection->marketplace);
        $previousStatus = strtoupper((string) $order->status);

        try {
            if ($slug === 'back-market') {
                if (! $connector instanceof BackMarketConnector) {
                    throw new BusinessRuleException(
                        message: 'Back Market status updates require the real marketplace connection.',
                        status: 422,
                    );
                }

                $updated = $this->updateBackMarketStatus(
                    $connector,
                    $order,
                    strtoupper($status),
                    $trackingUrl,
                );
            } elseif ($slug === 'refurbed') {
                if (! $connector instanceof RefurbedConnector) {
                    throw new BusinessRuleException(
                        message: 'Refurbed status updates require the real marketplace connection.',
                        status: 422,
                    );
                }

                $updated = $connector->updateOrderStatus(
                    order: $order,
                    status: strtoupper($status),
                    trackingUrl: $trackingUrl,
                    itemIdentifiers: $itemIdentifiers,
                );
            } else {
                throw new BusinessRuleException(
                    message: 'Marketplace status updates are not available for this marketplace yet.',
                    status: 422,
                );
            }

            $this->restoreStockForTerminalTransition($updated, $previousStatus);

            return $updated->fresh(['connection.marketplace', 'items']);
        } catch (RefurbedApiException $exception) {
            throw $exception;
        } catch (BusinessRuleException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BusinessRuleException(
                message: 'The marketplace rejected the order status update. The local order was not changed.',
                status: 422,
            );
        }
    }

    private function updateBackMarketStatus(
        BackMarketConnector $connector,
        MarketplaceOrder $order,
        string $status,
        ?string $trackingUrl,
    ): MarketplaceOrder {
        $newState = match ($status) {
            'ACCEPTED', 'ACCEPT', 'CONFIRMED' => 2,
            'SHIPPED', 'SHIPPING', 'SENT' => 3,
            'CANCELLED', 'CANCELED', 'REJECTED' => 4,
            default => throw new BusinessRuleException(
                message: 'Unsupported Back Market status. Use accepted, shipped, or cancelled.',
                status: 422,
            ),
        };

        $payload = ['new_state' => $newState];
        if ($newState === 3) {
            $payload['tracking_number'] = $order->tracking_number
                ?: throw new BusinessRuleException(
                    message: 'A tracking number is required before marking a Back Market order as shipped.',
                    status: 422,
                );
            if ($trackingUrl) {
                $payload['tracking_url'] = $trackingUrl;
            } elseif ($order->tracking_url) {
                $payload['tracking_url'] = $order->tracking_url;
            }
            if ($order->shipper) {
                $payload['shipper'] = $order->shipper;
            }
        }

        $remote = $connector->updateOrder($order->connection, (string) $order->external_order_id, $payload);
        app(BackMarketOrderSynchronizer::class)->syncOrder($order->connection, $remote);

        return $order->fresh(['connection.marketplace', 'items']) ?? $order;
    }

    private function restoreStockForTerminalTransition(
        MarketplaceOrder $order,
        string $previousStatus,
    ): void {
        $terminal = ['REJECTED', 'CANCELLED', 'RETURNED'];
        $currentStatus = strtoupper((string) $order->status);
        if (in_array($previousStatus, $terminal, true) || ! in_array($currentStatus, $terminal, true)) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $quantities = $order->items->groupBy('sku')->map(fn ($items) => $items->sum('quantity'));
            foreach ($quantities as $sku => $quantity) {
                $offer = MarketplaceOffer::where('marketplace_connection_id', $order->marketplace_connection_id)
                    ->where('sku', $sku)->lockForUpdate()->first();
                if ($offer) {
                    $offer->increment('stock', (int) $quantity);
                }
            }
        });
    }

    public function refresh(User $user, int $orderId): MarketplaceOrder
    {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);

        return $connector->refreshOrder($order);
    }

    public function returns(User $user, int $orderId): array
    {
        $order = $this->findForUser($user, $orderId);

        return MarketplaceOrderReturn::where('marketplace_order_id', $order->id)
            ->latest('return_initiated_at')
            ->get()
            ->map(fn (MarketplaceOrderReturn $itemReturn) => $this->presentReturn($itemReturn))
            ->all();
    }

    public function allReturns(User $user): array
    {
        return MarketplaceOrderReturn::query()
            ->whereHas('connection', fn ($query) => $query->whereBelongsTo($user))
            ->with('connection:id,account_name')
            ->latest('return_initiated_at')
            ->get()
            ->map(fn (MarketplaceOrderReturn $itemReturn) => $this->presentReturn($itemReturn))
            ->all();
    }

    private function presentReturn(MarketplaceOrderReturn $itemReturn): array
    {
        return [
            'id' => $itemReturn->external_id,
            'order_id' => $itemReturn->external_order_id,
            'order_item_id' => $itemReturn->external_order_item_id,
            'instance_name' => $itemReturn->instance_name,
            'item_identifiers' => $itemReturn->item_identifiers ?? [],
            'return_initiated_at' => $itemReturn->return_initiated_at?->toISOString(),
            'state' => $itemReturn->state,
            'delivered_at' => $itemReturn->delivered_at?->toISOString(),
            'tracking_number' => $itemReturn->tracking_number,
            'tracking_url' => $itemReturn->tracking_url,
            'ticket_topic' => $itemReturn->ticket_topic,
            'ticket_url' => $itemReturn->ticket_url,
            'item_within_trial_period' => $itemReturn->item_within_trial_period,
            'local_order_id' => $itemReturn->marketplace_order_id,
            'marketplace_account' => $itemReturn->connection?->account_name,
            'last_synced_at' => $itemReturn->last_synced_at?->toISOString(),
        ];
    }

    public function calculateRefund(
        User $user,
        int $orderId,
        ?int $itemId = null,
        ?string $targetPaidAmount = null,
    ): array {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);

        if ($itemId === null) {
            return $connector->calculateOrderRefund($order);
        }

        $item = $order->items()->findOrFail($itemId);

        return $connector->calculateOrderItemRefund(
            $order,
            (string) $item->external_order_item_id,
            (string) $targetPaidAmount,
        );
    }

    public function refund(
        User $user,
        int $orderId,
        ?int $itemId = null,
        ?string $targetPaidAmount = null,
    ): array {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);
        $result = $itemId === null
            ? $connector->refundOrder($order)
            : $connector->refundOrderItem(
                $order,
                (string) $order->items()->findOrFail($itemId)->external_order_item_id,
                (string) $targetPaidAmount,
            );

        $updated = $connector->refreshOrder($order);

        return ['refund' => $result, 'order' => $updated];
    }

    public function invoiceUrl(User $user, int $orderId): string
    {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);

        if ($order->custom_invoice_path && Storage::disk('local')->exists($order->custom_invoice_path)) {
            return url("/api/orders/{$order->id}/invoice/custom");
        }

        if ($order->invoice_url) {
            return $order->invoice_url;
        }

        return $this->cacheInvoiceUrl($user, $orderId);
    }

    public function cacheInvoiceUrl(User $user, int $orderId): string
    {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);
        $url = $connector->getOrderInvoiceUrl($order);
        $order->update(['invoice_url' => $url, 'invoice_url_cached_at' => now()]);

        return $url;
    }

    public function customInvoicePath(User $user, int $orderId): string
    {
        $order = $this->findForUser($user, $orderId);

        if (! $order->custom_invoice_path || ! Storage::disk('local')->exists($order->custom_invoice_path)) {
            throw new BusinessRuleException('No custom bill is available for this order.', 404);
        }

        return Storage::disk('local')->path($order->custom_invoice_path);
    }

    public function createShippingLabel(User $user, int $orderId, array $labelData): array
    {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);
        $shippingLabel = $connector->createShippingLabel($order, $labelData);
        $tracking = $shippingLabel['tracking_data'] ?? [];
        $rawPayload = $order->raw_payload ?? [];
        $rawPayload['shipping_label'] = $shippingLabel;

        MarketplaceShippingLabel::updateOrCreate([
            'marketplace_order_id' => $order->id,
            'external_id' => (string) $shippingLabel['id'],
        ], [
            'normal_printer_urls' => $shippingLabel['normal_printer_urls'] ?? null,
            'label_printer_url' => $shippingLabel['label_printer_url'] ?? null,
            'carrier' => is_array($tracking) ? ($tracking['carrier'] ?? null) : null,
            'tracking_number' => is_array($tracking) ? ($tracking['tracking_number'] ?? null) : null,
            'tracking_url' => is_array($tracking) ? ($tracking['tracking_url'] ?? null) : null,
            'parcel_weight' => $shippingLabel['parcel_weight'] ?? null,
            'merchant_address' => $shippingLabel['merchant_address'] ?? null,
            'raw_payload' => $shippingLabel,
        ]);

        $order->update([
            'tracking_number' => is_array($tracking)
                ? ($tracking['tracking_number'] ?? $order->tracking_number)
                : $order->tracking_number,
            'tracking_url' => is_array($tracking)
                ? ($tracking['tracking_url'] ?? $order->tracking_url)
                : $order->tracking_url,
            'shipper' => is_array($tracking)
                ? ($tracking['carrier'] ?? $order->shipper)
                : $order->shipper,
            'raw_payload' => $rawPayload,
            'modified_at' => now(),
        ]);

        return $shippingLabel;
    }

    public function merchantAddresses(User $user, int $orderId): array
    {
        $order = $this->findForUser($user, $orderId);

        $addresses = MarketplaceMerchantAddress::query()
            ->where('marketplace_connection_id', $order->marketplace_connection_id)
            ->orderBy('company_name')
            ->orderBy('external_id')
            ->get();

        return $addresses->map(fn (MarketplaceMerchantAddress $address) => [
            'id' => $address->external_id,
            'version' => $address->version,
            'label' => $address->label,
            'first_name' => $address->first_name,
            'family_name' => $address->family_name,
            'company_name' => $address->company_name,
            'country_code' => $address->country_code,
            'post_code' => $address->post_code,
            'town' => $address->town,
            'street_name' => $address->street_name,
            'house_no' => $address->house_no,
            'supplement' => $address->supplement,
            'phone_number' => $address->phone_number,
            'type' => $address->type,
        ])->all();
    }

    public function updateAddress(User $user, int $orderId, string $type, array $address, bool $syncRemote = true): MarketplaceOrder
    {
        $order = $this->findForUser($user, $orderId);
        $storedAddress = [
            'first_name' => $address['first_name'], 'family_name' => $address['family_name'],
            'company' => $address['company'] ?? null, 'street_name' => $address['street_name'],
            'house_no' => $address['house_no'] ?? null, 'supplement' => $address['supplement'] ?? null,
            'town' => $address['town'], 'post_code' => $address['post_code'],
            'country_code' => strtoupper($address['country_code']), 'phone' => $address['phone'] ?? null,
            'email' => $address['email'] ?? null,
        ];

        if ($syncRemote && $type === 'shipping' && $order->connection->marketplace->slug === 'refurbed') {
            [, $connector] = $this->refurbedOrder($user, $orderId);
            $remoteAddress = $storedAddress;
            unset($remoteAddress['email']);
            $remoteAddress['phone'] = $remoteAddress['phone'] ?? '';
            $connector->updateShippingLabelAddress($order, $remoteAddress);
        }

        $order->update([$type === 'shipping' ? 'shipping_address' : 'billing_address' => $storedAddress]);

        return $order->fresh(['connection.marketplace', 'items']);
    }

    public function uploadInvoice(User $user, int $orderId, string $contents): array
    {
        [$order, $connector] = $this->refurbedOrder($user, $orderId);
        $rawPayload = $order->raw_payload ?? [];
        $path = "order-invoices/{$order->id}.pdf";

        Storage::disk('local')->put($path, $contents);
        $order->update(['custom_invoice_path' => $path]);


        try {
            $connector->uploadOrderInvoice($order, base64_encode($contents));
        } catch (RefurbedApiException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'invoiceable')) {
                throw $exception;
            }

            $rawPayload['is_invoiceable'] = false;
            $order->update(['raw_payload' => $rawPayload]);

            throw new BusinessRuleException('The bill was saved in CelleXa, but Refurbed does not currently allow invoice uploads for this order.', 422);
        }

        $rawPayload['is_invoiceable'] = true;
        $rawPayload['has_invoice'] = true;
        $order->update(['raw_payload' => $rawPayload]);

        return ['refurbed_uploaded' => true];
    }

    public function saveInvoice(User $user, int $orderId, string $contents): MarketplaceOrder
    {
        $order = $this->findForUser($user, $orderId);
        $path = "order-invoices/{$order->id}.pdf";
        Storage::disk('local')->put($path, $contents);
        $rawPayload = array_replace($order->raw_payload ?? [], ['has_invoice' => true]);
        $order->update(['custom_invoice_path' => $path, 'raw_payload' => $rawPayload]);

        return $order;
    }

    public function uploadStoredInvoice(User $user, int $orderId): array
    {
        $order = $this->findForUser($user, $orderId);
        if (! $order->custom_invoice_path || ! Storage::disk('local')->exists($order->custom_invoice_path)) {
            throw new BusinessRuleException('The locally saved invoice could not be found.', 404);
        }

        return $this->uploadInvoice(
            $user,
            $orderId,
            Storage::disk('local')->get($order->custom_invoice_path),
        );
    }

    public function updateItemShipping(User $user, int $orderId, int $itemId, string $amount): MarketplaceOrder
    {
        $order = $this->findForUser($user, $orderId);
        $item = $order->items()->findOrFail($itemId);
        $item->update(['shipping_override_amount' => number_format((float) $amount, 2, '.', '')]);
        $shipping = (float) $order->items->sum(fn ($orderItem) => (float) ($orderItem->shipping_override_amount ?? $orderItem->shipping_amount));
        $originalShipping = (float) $order->items->sum('shipping_amount');
        $sourceTotal = (float) data_get(
            $order->raw_payload,
            'total_charged',
            $order->total_amount
        );
        $subtotal = max(
            0,
            $sourceTotal - $originalShipping - (float) $order->tax_amount
        );
        $total = $subtotal + $shipping + (float) $order->tax_amount;

        $order->update([
            'shipping_amount' => number_format($shipping, 2, '.', ''),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total_amount' => number_format($total, 2, '.', ''),
        ]);

        return $order->fresh(['connection.marketplace', 'items']);
    }

    public function sendBuyerEmail(User $user, int $orderId, string $template): void
    {
        $order = $this->findForUser($user, $orderId);
        $email = data_get($order->shipping_address, 'email')
            ?? data_get($order->billing_address, 'email')
            ?? data_get($order->raw_payload, 'customer_email');

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessRuleException('This order does not contain a valid buyer email address.', 422);
        }

        $messages = [
            'confirmation' => ['Order confirmation', "Your order {$order->external_order_id} has been confirmed."],
            'correspondence' => ['Message about your order', "We are contacting you about order {$order->external_order_id}."],
            'reimbursement' => ['Order reimbursement', "A reimbursement update is available for order {$order->external_order_id}."],
            'payment_reminder' => ['Payment reminder', "Payment is still required for order {$order->external_order_id}."],
            'evaluation_reminder' => ['Order evaluation', "Please share your feedback for order {$order->external_order_id}."],
            'shipment_confirmation' => ['Shipment confirmation', "Order {$order->external_order_id} has been dispatched. Tracking: ".($order->tracking_url ?: 'not available')],
        ];
        [$subject, $body] = $messages[$template];

        try {
            Mail::raw($body, fn ($message) => $message->to($email)->subject($subject));
        } catch (Throwable $exception) {
            report($exception);
            throw new BusinessRuleException('The email could not be sent. Check the Laravel mail configuration and try again.', 503);
        }
    }

    private function refurbedOrder(User $user, int $orderId): array
    {
        $order = $this->findForUser($user, $orderId);
        if ($order->connection->marketplace->slug !== 'refurbed') {
            throw new BusinessRuleException('This action is available for Refurbed orders only.', 422);
        }
        $connector = $this->connectorManager->connector($order->connection->marketplace);
        if (! $connector instanceof RefurbedConnector) {
            throw new BusinessRuleException('This action requires the real Refurbed connection.', 422);
        }

        return [$order, $connector];
    }
}
