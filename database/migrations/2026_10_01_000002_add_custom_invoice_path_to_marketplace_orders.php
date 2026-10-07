<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->string('custom_invoice_path')->nullable()->after('vat_number');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropColumn('custom_invoice_path');
        });
    }
};
