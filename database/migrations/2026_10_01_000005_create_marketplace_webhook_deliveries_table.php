<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('external_id');
            $table->string('status', 30)->default('received')->index();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['marketplace_connection_id', 'event_type', 'external_id'],
                'marketplace_webhook_delivery_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_webhook_deliveries');
    }
};
