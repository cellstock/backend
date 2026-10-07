<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('ean');
            $table->boolean('pending_upload')->default(false)->index()->after('tags');
            $table->boolean('is_active')->default(true)->index()->after('pending_upload');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table): void {
            $table->dropColumn(['tags', 'pending_upload', 'is_active']);
        });
    }
};
