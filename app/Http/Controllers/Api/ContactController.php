<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        ContactMessage::query()->create($data);

        return ApiResponse::success(message: 'Thank you. Your message has been received.', status: 201);
    }
}
