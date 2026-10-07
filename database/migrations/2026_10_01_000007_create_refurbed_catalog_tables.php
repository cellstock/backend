<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refurbed_catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('status')->default('queued')->index();
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('imported_rows')->default(0);
            $table->unsignedBigInteger('failed_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refurbed_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_import_id')
                ->constrained('refurbed_catalog_imports')
                ->cascadeOnDelete();
            $table->string('instance_id', 64)->unique();
            $table->string('name', 1000)->nullable();
            $table->string('name_de', 1000)->nullable();
            $table->longText('attributes')->nullable();
            $table->longText('attributes_de')->nullable();
            $table->string('main_category', 255)->nullable()->index();
            $table->timestamps();

            $table->fullText(['name', 'name_de', 'main_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refurbed_catalog_items');
        Schema::dropIfExists('refurbed_catalog_imports');
    }
};
