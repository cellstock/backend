<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_connections', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->after('settings');
            $table->timestamp('webhook_enabled_at')->nullable()->after('webhook_secret');
            $table->timestamp('webhook_last_received_at')->nullable()->after('webhook_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_connections', function (Blueprint $table): void {
            $table->dropColumn(['webhook_secret', 'webhook_enabled_at', 'webhook_last_received_at']);
        });
    }
};
