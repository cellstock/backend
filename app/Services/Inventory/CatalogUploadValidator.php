<?php
namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class CatalogUploadValidator
{
    public function validate(string $path): void
    {
        $reader = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' ? new CsvReader : new XlsxReader;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn ($v) => trim((string) ($v ?? '')), $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($v) => strtolower(trim(preg_replace('/\s+/', ' ', $v), "\xEF\xBB\xBF ")), $values);
                        $missing = array_diff(['instance id', 'name', 'name de', 'attributes', 'attributes de', 'main category'], $header);
                        if ($missing) throw ValidationException::withMessages(['file' => 'Missing catalog columns: '.implode(', ', $missing).'.']);
                        continue;
                    }
                    if (($values[array_search('instance id', $header, true)] ?? '') !== '') return;
                }
                break;
            }
            throw ValidationException::withMessages(['file' => 'The catalog is empty or has no rows with an Instance ID.']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'The catalog could not be read. Upload a valid XLSX or CSV file.']);
        } finally {
            $reader->close();
        }
    }
}
