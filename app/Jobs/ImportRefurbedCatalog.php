<?php

namespace App\Jobs;

use App\Models\RefurbedCatalogImport;
use App\Models\RefurbedCatalogItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
        $import = RefurbedCatalogImport::query()->findOrFail($this->catalogImportId);
        $path = Storage::disk('local')->path($import->stored_path);
        $reader = $this->reader($path);
        $imported = 0;
        $failed = 0;
        $batch = [];
        $header = [];

        $import->update([
            'status' => 'processing',
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ]);

        try {
            app(\App\Services\Inventory\CatalogUploadValidator::class)->validate($path);
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $values = $this->values($row);

                    if ($rowNumber === 1) {
                        $header = $this->header($values);
                        $this->validateHeader($header);

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

                break;
            }

            if ($batch !== []) {
                $this->store($batch);
            }

            RefurbedCatalogItem::query()
                ->where('catalog_import_id', '!=', $import->id)
                ->delete();

            $import->update([
                'status' => 'completed',
                'total_rows' => $imported + $failed,
                'imported_rows' => $imported,
                'failed_rows' => $failed,
                'completed_at' => now(),
            ]);
        } finally {
            $reader->close();
            Storage::disk('local')->delete($import->stored_path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        RefurbedCatalogImport::query()
            ->whereKey($this->catalogImportId)
            ->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Catalog import failed.',
                'completed_at' => now(),
            ]);
    }

    private function reader(string $path): ReaderInterface
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv'
            ? new CsvReader
            : new XlsxReader;
    }

    private function values(Row $row): array
    {
        return array_map(
            fn (mixed $value): string => trim((string) ($value ?? '')),
            $row->toArray()
        );
    }

    private function header(array $values): array
    {
        return collect($values)
            ->mapWithKeys(fn (string $value, int $index) => [
                strtolower(trim(preg_replace('/\s+/', ' ', $value))) => $index,
            ])
            ->all();
    }

    private function validateHeader(array $header): void
    {
        $required = ['instance id', 'name', 'name de', 'attributes', 'attributes de', 'main category'];
        $missing = array_diff($required, array_keys($header));

        if ($missing !== []) {
            throw new RuntimeException('Missing catalog columns: '.implode(', ', $missing).'.');
        }
    }

    private function item(array $values, array $header, int $importId): ?array
    {
        $instanceId = $values[$header['instance id']] ?? '';

        if ($instanceId === '') {
            return null;
        }

        return [
            'catalog_import_id' => $importId,
            'instance_id' => $instanceId,
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
            ['catalog_import_id', 'name', 'name_de', 'attributes', 'attributes_de', 'main_category', 'updated_at']
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
