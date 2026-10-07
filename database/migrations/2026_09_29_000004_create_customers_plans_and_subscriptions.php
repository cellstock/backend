<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0); $table->string('currency', 3)->default('EUR'); $table->string('billing_interval')->default('month');
            $table->json('features')->nullable(); $table->boolean('is_active')->default(true)->index(); $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active')->index(); $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false); $table->timestamps(); $table->unique('user_id');
        });
    }
    public function down(): void { Schema::dropIfExists('subscriptions'); Schema::dropIfExists('plans'); Schema::dropIfExists('customers'); }
};
