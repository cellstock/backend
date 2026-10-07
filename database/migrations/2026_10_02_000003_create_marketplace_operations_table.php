<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 80)->index();
            $table->string('entity_type', 40)->nullable();
            $table->string('entity_id')->nullable()->index();
            $table->longText('payload')->nullable();
            $table->longText('result')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->text('invoice_url')->nullable();
            $table->timestamp('invoice_url_cached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropColumn(['invoice_url', 'invoice_url_cached_at']);
        });
        Schema::dropIfExists('marketplace_operations');
    }
};
