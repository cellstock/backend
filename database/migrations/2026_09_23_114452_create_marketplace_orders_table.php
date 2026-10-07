<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('marketplace_connection_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('external_order_id');

            $table->string('status')->nullable()->index();
            $table->string('country_code', 10)->nullable()->index();
            $table->string('currency', 3)->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->string('payment_method')->nullable();

            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('shipper')->nullable();

            // These will be encrypted by the model.
            $table->longText('shipping_address')->nullable();
            $table->longText('billing_address')->nullable();
            $table->longText('raw_payload')->nullable();

            $table->timestamp('ordered_at')->nullable()->index();
            $table->timestamp('modified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'marketplace_connection_id',
                    'external_order_id',
                ],
                'marketplace_orders_connection_external_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
