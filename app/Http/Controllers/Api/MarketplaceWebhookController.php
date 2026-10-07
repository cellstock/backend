<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marketplace;
use App\Services\Marketplaces\RefurbedWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceWebhookController extends Controller
{
    public function __construct(private readonly RefurbedWebhookService $webhooks) {}

    public function show(Request $request, Marketplace $marketplace): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->webhooks->details($request->user(), $marketplace),
        ]);
    }

    public function store(Request $request, Marketplace $marketplace): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:generate,request_activation'],
        ]);

        $details = $data['action'] === 'generate'
            ? $this->webhooks->generate($request->user(), $marketplace)
            : $this->webhooks->requestActivation($request->user(), $marketplace);

        return response()->json([
            'success' => true,
            'message' => $data['action'] === 'generate'
                ? 'Notification URL generated.'
                : 'Activation request queued for Refurbed.',
            'data' => $details,
        ]);
    }
}
