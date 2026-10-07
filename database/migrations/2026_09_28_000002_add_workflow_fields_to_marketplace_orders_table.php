<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->string('workflow_status', 30)->nullable()->after('status');
            $table->string('payment_status', 30)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropColumn(['workflow_status', 'payment_status']);
        });
    }
};
