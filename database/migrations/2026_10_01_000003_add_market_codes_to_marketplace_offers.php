<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->json('market_codes')->nullable()->after('reference_min_price');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->dropColumn('market_codes');
        });
    }
};
