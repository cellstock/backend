<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_push_preferences', function (Blueprint $table) {
            $table->json('market_last_pushed')->nullable()->after('last_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_push_preferences', function (Blueprint $table) {
            $table->dropColumn('market_last_pushed');
        });
    }
};
