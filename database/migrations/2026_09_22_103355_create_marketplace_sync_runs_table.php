<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'marketplace_sync_runs',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('marketplace_connection_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('type');
                $table->string('status')->default('queued');

                $table->unsignedInteger('processed_count')
                    ->default(0);

                $table->unsignedInteger('success_count')
                    ->default(0);

                $table->unsignedInteger('failed_count')
                    ->default(0);

                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();

                $table->timestamps();

                $table->index([
                    'user_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_runs');
    }
};