<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->text('internal_comment')->nullable();
            $table->date('contact_before')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropColumn(['internal_comment', 'contact_before']);
        });
    }
};
