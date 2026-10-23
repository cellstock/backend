<?php

namespace App\Jobs;

use App\Models\RefurbedCatalogImport;
use App\Models\RefurbedCatalogItem;
use App\Services\Inventory\CatalogSpreadsheet;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ImportRefurbedCatalog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $catalogImportId)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return 'refurbed-catalog';
    }

    public function handle(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $import = RefurbedCatalogImport::query()->findOrFail($this->catalogImportId);
        $path = Storage::disk('local')->path($import->stored_path);
        $spreadsheet = app(CatalogSpreadsheet::class);
        $reader = null;
        $imported = 0;
        $failed = 0;
        $batch = [];

        $import->update([
            'status' => 'processing',
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ]);

        try {
            // Single pass only — validating first then importing re-reads a 40MB+
            // XLSX (shared strings) and often times out or looks like an empty file.
            $reader = $spreadsheet->reader($path);
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $header = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $values = $spreadsheet->values($row);

                    if ($spreadsheet->rowIsEmpty($values)) {
                        continue;
                    }

                    if ($header === []) {
                        $candidate = $spreadsheet->headerMap($values);
                        if (! $spreadsheet->hasRequiredColumns($candidate)) {
                            continue;
                        }
                        $header = $candidate;

                        continue;
                    }

                    $item = $this->item($values, $header, $import->id);

                    if ($item === null) {
                        $failed++;

                        continue;
                    }

                    $batch[] = $item;
                    $imported++;

                    if (count($batch) === 1000) {
                        $this->store($batch);
                        $batch = [];
                        $this->progress($import, $imported, $failed);
                    }
                }
            }

            if ($batch !== []) {
                $this->store($batch);
            }

            if ($imported === 0) {
                throw new RuntimeException(
                    'The catalog is empty or has no rows with an Instance ID. Your file’s first sheet is empty — catalog rows are on another sheet (for example “Refurbed Orders”).'
                );
            }

            // Mark completed before cleanup so a queue timeout/retry cannot
            // overwrite a successful import as "attempted too many times".
            $import->update([
                'status' => 'completed',
                'total_rows' => $imported + $failed,
                'imported_rows' => $imported,
                'failed_rows' => $failed,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            RefurbedCatalogItem::query()
                ->where('catalog_import_id', '!=', $import->id)
                ->delete();
        } catch (Throwable $exception) {
            $import->refresh();

            // Already marked completed (e.g. cleanup after success failed) — keep it.
            if ($import->status === 'completed') {
                return;
            }

            $import->update([
                'status' => 'failed',
                'error_message' => $this->formatError($exception),
                'completed_at' => now(),
            ]);
        } finally {
            if ($reader !== null) {
                $reader->close();
            }
            Storage::disk('local')->delete($import->stored_path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = RefurbedCatalogImport::query()->find($this->catalogImportId);

        if ($import === null) {
            return;
        }

        // Queue retry_after races call failed() while the first worker is still
        // importing (or after it finished). Never clobber a real success.
        if ($import->status === 'completed') {
            return;
        }

        if ((int) $import->imported_rows > 0
            && RefurbedCatalogItem::query()
                ->where('catalog_import_id', $import->id)
                ->exists()) {
            $import->update([
                'status' => 'completed',
                'error_message' => null,
                'completed_at' => $import->completed_at ?? now(),
            ]);

            return;
        }

        $import->update([
            'status' => 'failed',
            'error_message' => $this->formatError($exception) ?: 'Catalog import failed.',
            'completed_at' => now(),
        ]);
    }

    private function formatError(?Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $messages = collect($exception->errors())->flatten()->filter()->values();

            return $messages->first() ?: ($exception->getMessage() ?: 'The catalog file is invalid.');
        }

        return $exception?->getMessage() ?: 'Catalog import failed.';
    }

    private function item(array $values, array $header, int $importId): ?array
    {
        $instanceId = trim((string) ($values[$header['instance id']] ?? ''));

        if ($instanceId === '') {
            return null;
        }

        $skuIndex = $header['sku'] ?? null;

        return [
            'catalog_import_id' => $importId,
            'instance_id' => $instanceId,
            'sku' => $skuIndex === null ? null : $this->nullable($values[$skuIndex] ?? ''),
            'name' => $this->nullable($values[$header['name']] ?? ''),
            'name_de' => $this->nullable($values[$header['name de']] ?? ''),
            'attributes' => $this->nullable($values[$header['attributes']] ?? ''),
            'attributes_de' => $this->nullable($values[$header['attributes de']] ?? ''),
            'main_category' => $this->nullable($values[$header['main category']] ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function store(array $items): void
    {
        RefurbedCatalogItem::query()->upsert(
            $items,
            ['instance_id'],
            ['catalog_import_id', 'sku', 'name', 'name_de', 'attributes', 'attributes_de', 'main_category', 'updated_at']
        );
    }

    private function progress(RefurbedCatalogImport $import, int $imported, int $failed): void
    {
        $import->update([
            'total_rows' => $imported + $failed,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
        ]);
    }
}
