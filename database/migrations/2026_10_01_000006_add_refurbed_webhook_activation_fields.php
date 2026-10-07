<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_connections', function (Blueprint $table) {
            $table->string('webhook_activation_status')
                ->default('not_requested')
                ->after('webhook_last_received_at');
            $table->timestamp('webhook_activation_requested_at')
                ->nullable()
                ->after('webhook_activation_status');
            $table->timestamp('webhook_activated_at')
                ->nullable()
                ->after('webhook_activation_requested_at');
            $table->text('webhook_activation_error')
                ->nullable()
                ->after('webhook_activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_connections', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_activation_status',
                'webhook_activation_requested_at',
                'webhook_activated_at',
                'webhook_activation_error',
            ]);
        });
    }
};
