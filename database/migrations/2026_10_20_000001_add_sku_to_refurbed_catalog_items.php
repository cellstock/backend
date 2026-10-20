<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refurbed_catalog_items', function (Blueprint $table) {
            $table->string('sku', 255)->nullable()->after('instance_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('refurbed_catalog_items', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->dropColumn('sku');
        });
    }
};
