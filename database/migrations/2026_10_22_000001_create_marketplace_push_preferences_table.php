<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_push_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('marketplace_slug', 64);
            $table->json('slots');
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'marketplace_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_push_preferences');
    }
};
