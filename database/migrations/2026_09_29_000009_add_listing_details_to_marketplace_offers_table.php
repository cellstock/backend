<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->json('listing_details')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->dropColumn('listing_details');
        });
    }
};
