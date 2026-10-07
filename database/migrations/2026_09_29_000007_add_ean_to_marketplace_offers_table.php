<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->string('ean')->nullable()->index()->after('instance_name');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->dropColumn('ean');
        });
    }
};
