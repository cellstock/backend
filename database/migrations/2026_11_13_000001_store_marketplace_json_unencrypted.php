<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'marketplace_orders' => ['shipping_address', 'billing_address', 'raw_payload'],
            'marketplace_order_items' => ['raw_payload'],
            'marketplace_order_returns' => ['item_identifiers', 'raw_payload'],
            'marketplace_shipping_labels' => ['merchant_address', 'raw_payload'],
            'marketplace_merchant_addresses' => ['raw_payload'],
            'marketplace_offers' => ['raw_payload'],
            'marketplace_operations' => ['payload', 'result'],
            'marketplace_shipping_profiles' => ['raw_payload'],
        ];

        foreach ($columns as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($fields as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $this->convertColumnToPlainJson($table, $column);
            }
        }
    }

    public function down(): void
    {
        // Re-encrypting would require model casts; left intentionally empty.
    }

    private function convertColumnToPlainJson(string $table, string $column): void
    {
        DB::table($table)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $raw = $row->{$column};

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $plainJson = $this->toPlainJsonString($raw);

                    if ($plainJson === null || $plainJson === $raw) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => $plainJson]);
                }
            });
    }

    private function toPlainJsonString(string $raw): ?string
    {
        json_decode($raw);

        if (json_last_error() === JSON_ERROR_NONE) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }

        $decoded = json_decode($decrypted, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
