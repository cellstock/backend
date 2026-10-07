<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'marketplace_connections',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('marketplace_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->string('account_name')->nullable();
                $table->string('external_account_id')->nullable();

                $table->text('credentials');
                $table->json('settings')->nullable();

                $table->string('status')
                    ->default('pending');

                $table->timestamp('last_tested_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique([
                    'user_id',
                    'marketplace_id',
                ]);

                $table->index([
                    'user_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_connections');
    }
};