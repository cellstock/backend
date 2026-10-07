<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Marketplaces\SynchronizationIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SynchronizationIssueController extends Controller
{
    public function __construct(private readonly SynchronizationIssueService $issues) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['issues' => $this->issues->list($request->user())]]);
    }

    public function retry(Request $request, string $issue): JsonResponse
    {
        $this->issues->retry($request->user(), $issue);

        return response()->json([
            'success' => true,
            'message' => 'Retry queued. CelleXa will update the status automatically.',
        ], 202);
    }
}
