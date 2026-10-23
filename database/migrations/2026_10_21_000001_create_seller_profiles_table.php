<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->longText('about_me')->nullable();
            $table->longText('contact_information')->nullable();
            $table->longText('terms_and_conditions')->nullable();
            $table->longText('return_policy')->nullable();
            $table->boolean('push_after_upload')->default(false);
            $table->boolean('use_new_inventory_page')->default(false);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
