<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_merchant_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id');
            $table->string('external_id');
            $table->string('version')->nullable();
            $table->string('label')->nullable();
            $table->string('first_name')->nullable();
            $table->string('family_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('post_code', 32)->nullable();
            $table->string('town')->nullable();
            $table->string('street_name')->nullable();
            $table->string('house_no', 64)->nullable();
            $table->string('supplement')->nullable();
            $table->string('phone_number', 64)->nullable();
            $table->string('type', 40)->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('marketplace_connection_id', 'merchant_address_connection_fk')
                ->references('id')
                ->on('marketplace_connections')
                ->cascadeOnDelete();
            $table->unique(
                ['marketplace_connection_id', 'external_id'],
                'merchant_address_connection_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_merchant_addresses');
    }
};
