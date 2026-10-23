<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Services\Orders\OrderService;
use App\Models\MarketplaceOperation;
use App\Services\Marketplaces\MarketplaceOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly MarketplaceOperationService $operations,
    ) {
    }

    public function index(
        IndexOrderRequest $request
    ): AnonymousResourceCollection {
        $orders = $this->orderService->paginateForUser(
            $request->user(),
            $request->validated()
        );


        return OrderResource::collection($orders)
            ->additional([
                'success' => true,
            ]);
    }

    public function returnIndex(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['returns' => $this->orderService->allReturns($request->user())],
        ]);
    }

   public function show(
        Request $request,
        int $order
    ): JsonResponse {
        $marketplaceOrder =
            $this->orderService->findForUser(
                $request->user(),
                $order
            );

        return response()->json([
            'success' => true,
            'data' => [
                'order' => new OrderResource(
                    $marketplaceOrder
                ),
            ],
        ]);
}

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        int $order
    ): JsonResponse {
        $marketplaceOrder = $this->orderService->findForUser($request->user(), $order);
        $operation = $this->operations->queue($request->user(), $marketplaceOrder->marketplace_connection_id, 'order.status', 'order', $order, [
            'status' => $request->validated('status'),
            'tracking_url' => $request->validated('tracking_url'),
            'item_identifiers' => $request->validated('item_identifiers', []),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status update queued for Refurbed.',
            'data' => ['order' => new OrderResource($marketplaceOrder), 'operation_id' => $operation->id],
        ], 202);
    }

    public function refresh(Request $request, int $order): JsonResponse
    {
        $marketplaceOrder = $this->orderService->refresh($request->user(), $order);
        return response()->json(['success' => true, 'message' => 'Order synchronized from Refurbed.', 'data' => ['order' => new OrderResource($marketplaceOrder)]]);
    }

    public function returns(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['returns' => $this->orderService->returns($request->user(), $order)],
        ]);
    }

    public function calculateRefund(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:marketplace_order_items,id'],
            'target_paid_amount' => [Rule::requiredIf($request->filled('item_id')), 'nullable', 'decimal:0,2', 'min:0'],
        ]);

        return response()->json([
            'success' => true,
            'data' => ['refund' => $this->orderService->calculateRefund(
                $request->user(),
                $order,
                $data['item_id'] ?? null,
                isset($data['target_paid_amount']) ? (string) $data['target_paid_amount'] : null,
            )],
        ]);
    }

    public function refund(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:marketplace_order_items,id'],
            'target_paid_amount' => [Rule::requiredIf($request->filled('item_id')), 'nullable', 'decimal:0,2', 'min:0'],
        ]);
        $marketplaceOrder = $this->orderService->findForUser($request->user(), $order);
        $operation = $this->operations->queue($request->user(), $marketplaceOrder->marketplace_connection_id, 'order.refund', 'order', $order, [
            'item_id' => $data['item_id'] ?? null,
            'target_paid_amount' => isset($data['target_paid_amount']) ? (string) $data['target_paid_amount'] : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Refund queued for Refurbed processing.',
            'data' => ['operation_id' => $operation->id],
        ], 202);
    }

    public function invoice(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['url' => $this->orderService->invoiceUrl($request->user(), $order)],
        ]);
    }

    public function customInvoice(Request $request, int $order): BinaryFileResponse
    {
        return response()->file(
            $this->orderService->customInvoicePath($request->user(), $order),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function createShippingLabel(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'merchant_address_id' => ['required', 'string', 'max:255'],
            'parcel_weight' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'carrier' => ['required', 'string', Rule::in(['UNSPECIFIED', 'ups', 'dhl-express'])],
        ]);

        $label = $this->orderService->createShippingLabel($request->user(), $order, $data);

        return response()->json([
            'success' => true,
            'message' => 'Shipping label created.',
            'data' => ['shipping_label' => $label],
        ]);
    }

    public function merchantAddresses(Request $request, int $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'merchant_addresses' => $this->orderService->merchantAddresses(
                    $request->user(),
                    $order
                ),
            ],
        ]);
    }

    public function updateEditFields(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'internal_comment' => ['nullable', 'string', 'max:10000'],
            'contact_before' => ['nullable', 'date'],
            'workflow_status' => ['sometimes', 'string', 'in:open,dispatched,on_hold'],
            'payment_status' => ['sometimes', 'string', 'in:paid,refunded,partially_refunded,waiting_for_payment'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'shipper' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tracking_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $marketplaceOrder = $this->orderService->findForUser($request->user(), $order);
        $marketplaceOrder->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Order delivery options saved.',
            'data' => [
                'order' => new OrderResource(
                    $marketplaceOrder->fresh([
                        'connection.marketplace',
                        'items',
                        'shippingLabels',
                        'shippingAddress',
                        'billingAddress',
                    ]) ?? $marketplaceOrder
                ),
            ],
        ]);
    }

    public function updateItemShipping(Request $request, int $order, int $item): JsonResponse
    {
        $data = $request->validate(['shipping_amount' => ['required', 'numeric', 'min:0', 'max:999999.99']]);
        $updated = $this->orderService->updateItemShipping($request->user(), $order, $item, (string) $data['shipping_amount']);

        return response()->json(['success' => true, 'message' => 'Shipping cost updated.', 'data' => ['order' => new OrderResource($updated)]]);
    }

    public function sendBuyerEmail(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'in:confirmation,correspondence,reimbursement,payment_reminder,evaluation_reminder,shipment_confirmation']]);
        $this->orderService->sendBuyerEmail($request->user(), $order, $data['template']);

        return response()->json(['success' => true, 'message' => 'Email sent to the buyer.']);
    }

    public function updateAddress(Request $request, int $order, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['shipping', 'billing'], true), 404);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'], 'family_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'], 'street_name' => ['required', 'string', 'max:255'],
            'house_no' => ['nullable', 'string', 'max:50'], 'supplement' => ['nullable', 'string', 'max:255'],
            'town' => ['required', 'string', 'max:255'], 'post_code' => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2'],
            'phone' => [$type === 'shipping' ? 'required' : 'nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $updated = $this->orderService->updateAddress($request->user(), $order, $type, $data, syncRemote: false);
        $operation = null;
        if ($type === 'shipping') {
            $operation = $this->operations->queue($request->user(), $updated->marketplace_connection_id, 'order.shipping_address', 'order', $order, $data);
        }
        $message = $type === 'shipping' ? 'Shipping address saved and queued for Refurbed.' : 'Billing address updated in CelleXa.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['order' => new OrderResource($updated), 'operation_id' => $operation?->id],
        ]);
    }

    public function uploadInvoice(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:2048']]);
        $contents = file_get_contents($data['file']->getRealPath());
        abort_if($contents === false, 422, 'The uploaded PDF could not be read.');
        $result = $this->orderService->uploadInvoice($request->user(), $order, $contents);

        return response()->json(['success' => true, 'message' => 'Bill saved in CelleXa and uploaded to Refurbed.', 'data' => $result]);
    }
}
