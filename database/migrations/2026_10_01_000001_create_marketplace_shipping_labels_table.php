<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shipping_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->json('normal_printer_urls')->nullable();
            $table->text('label_printer_url')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();
            $table->decimal('parcel_weight', 10, 2)->nullable();
            $table->text('merchant_address')->nullable();
            $table->text('raw_payload');
            $table->timestamps();

            $table->unique(
                ['marketplace_order_id', 'external_id'],
                'shipping_labels_order_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shipping_labels');
    }
};
