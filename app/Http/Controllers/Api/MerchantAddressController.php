<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\MerchantAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantAddressController extends Controller
{
    public function __construct(private readonly MerchantAddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['addresses' => $this->addresses->list($request->user())]]);
    }

    public function sync(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['addresses' => $this->addresses->fetchFromRefurbed($request->user())]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Address saved in CelleXa and queued for synchronization.',
            'data' => ['address' => $this->addresses->create($request->user(), $this->validated($request, true))],
        ], 202);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Address updated in CelleXa and queued for synchronization.',
            'data' => ['address' => $this->addresses->update($request->user(), $address, $this->validated($request))],
        ], 202);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->addresses->delete($request->user(), $address);

        return response()->json(['success' => true, 'message' => 'Address deletion queued.'], 202);
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(['DELIVERY', 'RETURN'])],
            'label' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'company_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'country_code' => [$creating ? 'required' : 'sometimes', 'string', 'size:2'],
            'post_code' => [$creating ? 'required' : 'sometimes', 'string', 'max:32'],
            'town' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'street_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'house_no' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'supplement' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:64'],
        ]);
    }
}
