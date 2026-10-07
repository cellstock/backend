<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_shipping_carriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id');
            $table->string('name');
            $table->string('slug');
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('marketplace_connection_id', 'shipping_carrier_connection_fk')
                ->references('id')
                ->on('marketplace_connections')
                ->cascadeOnDelete();
            $table->unique(
                ['marketplace_connection_id', 'slug'],
                'shipping_carrier_connection_slug_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shipping_carriers');
    }
};
