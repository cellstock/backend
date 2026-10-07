<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_merchant_addresses', function (Blueprint $table): void {
            $table->string('sync_status', 30)->default('synced')->index();
            $table->text('sync_error')->nullable();
            $table->string('pending_action', 30)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_merchant_addresses', function (Blueprint $table): void {
            $table->dropColumn(['sync_status', 'sync_error', 'pending_action', 'retry_count']);
        });
    }
};
