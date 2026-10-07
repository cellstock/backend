<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_shipping_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('source_country_code', 3)->nullable()->index();
            $table->unsignedInteger('num_offers_assigned')->default(0);
            $table->longText('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_connection_id', 'external_id'], 'shipping_profiles_connection_external_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('marketplace_shipping_profiles'); }
};
