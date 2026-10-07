<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceSyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'processed_count' =>
                $this->processed_count,
            'success_count' =>
                $this->success_count,
            'failed_count' =>
                $this->failed_count,
            'started_at' =>
                $this->started_at?->toISOString(),
            'completed_at' =>
                $this->completed_at?->toISOString(),
            'error_message' =>
                $this->error_message,
            'created_at' =>
                $this->created_at?->toISOString(),
        ];
    }
}