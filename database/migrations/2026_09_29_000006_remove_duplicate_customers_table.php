<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('customers');
    }

    public function down(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('company')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
