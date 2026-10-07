<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_offer_id');
            $table->string('instance_id')->nullable()->index();
            $table->string('instance_name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->string('grading', 20)->nullable()->index();
            $table->string('warranty', 30)->nullable();
            $table->unsignedInteger('stock')->default(0)->index();
            $table->string('shipping_profile_id')->nullable();
            $table->string('reference_currency_code', 3)->nullable();
            $table->decimal('reference_price', 15, 2)->default(0);
            $table->decimal('reference_min_price', 15, 2)->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamp('marketplace_created_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['marketplace_connection_id', 'external_offer_id'], 'marketplace_offers_connection_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_offers');
    }
};
