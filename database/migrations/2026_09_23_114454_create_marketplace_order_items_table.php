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
        Schema::create(
            'marketplace_order_items',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('marketplace_order_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('external_order_item_id');
                $table->string('external_listing_id')->nullable();
                $table->string('external_product_id')->nullable();

                $table->string('sku')->nullable()->index();
                $table->string('title')->nullable();
                $table->string('brand')->nullable();

                $table->unsignedInteger('quantity')->default(1);

                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('shipping_amount', 15, 2)->default(0);
                $table->decimal('commission_amount', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);

                $table->string('currency', 3)->nullable();
                $table->string('status')->nullable()->index();
                $table->string('condition')->nullable();

                $table->string('return_reason')->nullable();
                $table->text('return_message')->nullable();

                $table->longText('raw_payload')->nullable();

                $table->timestamps();

                $table->unique(
                    [
                        'marketplace_order_id',
                        'external_order_item_id',
                    ],
                    'marketplace_order_items_order_external_unique'
                );
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_items');
    }
};
