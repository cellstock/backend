<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);

        return ApiResponse::success(data: ['profile' => $this->present($profile)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'about_me' => ['nullable', 'string', 'max:50000'],
            'contact_information' => ['nullable', 'string', 'max:50000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:50000'],
            'return_policy' => ['nullable', 'string', 'max:50000'],
            'push_after_upload' => ['sometimes', 'boolean'],
            'use_new_inventory_page' => ['sometimes', 'boolean'],
        ]);

        $profile = $this->profileFor($request);
        $profile->fill($data)->save();

        return ApiResponse::success(
            data: ['profile' => $this->present($profile->refresh())],
            message: 'Seller profile saved successfully.',
        );
    }

    public function logo(Request $request): StreamedResponse
    {
        $profile = $this->profileFor($request);
        $path = $profile->logo_path;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function updateLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpeg,jpg,gif', 'max:30'],
        ]);

        $profile = $this->profileFor($request);
        $oldPath = $profile->logo_path;
        $path = $request->file('logo')->store('seller-logos');
        $profile->update(['logo_path' => $path]);

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return ApiResponse::success(
            data: ['profile' => $this->present($profile->refresh())],
            message: 'Seller logo updated successfully.',
        );
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);

        if ($profile->logo_path) {
            Storage::disk('local')->delete($profile->logo_path);
            $profile->update(['logo_path' => null]);
        }

        return ApiResponse::success(
            data: ['profile' => $this->present($profile->refresh())],
            message: 'Seller logo removed successfully.',
        );
    }

    private function profileFor(Request $request): SellerProfile
    {
        return SellerProfile::query()->firstOrCreate(
            ['user_id' => $request->user()->id],
        );
    }

    private function present(SellerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'has_logo' => filled($profile->logo_path),
            'logo_url' => filled($profile->logo_path)
                ? '/api/seller-profile/logo'
                : null,
            'about_me' => $profile->about_me ?? '',
            'contact_information' => $profile->contact_information ?? '',
            'terms_and_conditions' => $profile->terms_and_conditions ?? '',
            'return_policy' => $profile->return_policy ?? '',
            'push_after_upload' => (bool) $profile->push_after_upload,
            'use_new_inventory_page' => (bool) $profile->use_new_inventory_page,
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
