<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_order_item_id' =>
                $this->external_order_item_id,
            'external_listing_id' =>
                $this->external_listing_id,
            'external_product_id' =>
                $this->external_product_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'brand' => $this->brand,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'shipping_amount' => $this->shipping_override_amount ?? $this->shipping_amount,
            'commission_amount' => $this->commission_amount,
            'tax_amount' => $this->tax_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'condition' => $this->condition,
            'return_reason' => $this->return_reason,
            'return_message' => $this->return_message,
            'item_identifiers' => is_array($this->raw_payload)
                ? ($this->raw_payload['item_identifiers'] ?? [])
                : [],
            'weight_kg' => is_array($this->raw_payload) ? ($this->raw_payload['weight_kg'] ?? null) : null,
            'ean' => is_array($this->raw_payload) ? ($this->raw_payload['ean'] ?? data_get($this->raw_payload, 'offer_data.ean')) : null,
        ];
    }
}
