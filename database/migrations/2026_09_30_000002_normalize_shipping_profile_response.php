<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $columns = [
            'version' => fn (Blueprint $table) => $table->string('version')->nullable()->after('external_id'),
            'is_current' => fn (Blueprint $table) => $table->boolean('is_current')->default(true)->after('version'),
            'is_deletable' => fn (Blueprint $table) => $table->boolean('is_deletable')->default(false)->after('is_current'),
            'source_country_market_name' => fn (Blueprint $table) => $table->string('source_country_market_name')->nullable()->after('source_country_code'),
            'marketplace_created_at' => fn (Blueprint $table) => $table->timestamp('marketplace_created_at')->nullable()->after('num_offers_assigned'),
        ];

        foreach ($columns as $name => $addColumn) {
            if (! Schema::hasColumn('marketplace_shipping_profiles', $name)) {
                Schema::table('marketplace_shipping_profiles', $addColumn);
            }
        }

        if (Schema::hasTable('marketplace_shipping_profile_destinations')) {
            Schema::drop('marketplace_shipping_profile_destinations');
        }

        Schema::create('marketplace_shipping_profile_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_shipping_profile_id');
            $table->string('market_code', 10);
            $table->string('market_name')->nullable();
            $table->boolean('is_site_market')->default(false);
            $table->string('currency_code', 3)->nullable();
            $table->decimal('shipping_costs', 15, 2)->nullable();
            $table->string('site_market_currency_code', 3)->nullable();
            $table->decimal('site_market_shipping_costs', 15, 2)->nullable();
            $table->unsignedSmallInteger('min_delivery_days')->nullable();
            $table->unsignedSmallInteger('max_delivery_days')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->foreign('marketplace_shipping_profile_id', 'shipping_profile_destination_profile_fk')
                ->references('id')
                ->on('marketplace_shipping_profiles')
                ->cascadeOnDelete();
            $table->unique(['marketplace_shipping_profile_id', 'market_code'], 'shipping_profile_destination_market_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shipping_profile_destinations');
        Schema::table('marketplace_shipping_profiles', function (Blueprint $table): void {
            $table->dropColumn(['version', 'is_current', 'is_deletable', 'source_country_market_name', 'marketplace_created_at']);
        });
    }
};
