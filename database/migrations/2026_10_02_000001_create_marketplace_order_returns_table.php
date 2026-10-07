<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('external_order_id')->index();
            $table->string('external_order_item_id')->nullable();
            $table->string('instance_name')->nullable();
            $table->string('state', 50)->nullable()->index();
            $table->timestamp('return_initiated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('ticket_topic')->nullable();
            $table->text('ticket_url')->nullable();
            $table->boolean('item_within_trial_period')->default(false);
            $table->longText('item_identifiers')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketplace_connection_id', 'external_id'],
                'marketplace_return_connection_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_returns');
    }
};
