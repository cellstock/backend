<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;
use Throwable;

class CatalogUploadValidator
{
    private CatalogSpreadsheet $spreadsheet;

    public function __construct(?CatalogSpreadsheet $spreadsheet = null)
    {
        $this->spreadsheet = $spreadsheet ?? new CatalogSpreadsheet;
    }

    public function validate(string $path): void
    {
        $reader = $this->spreadsheet->reader($path);

        try {
            $reader->open($path);
            $sawHeader = false;

            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $values = $this->spreadsheet->values($row);

                    if ($this->spreadsheet->rowIsEmpty($values)) {
                        continue;
                    }

                    if ($header === null) {
                        $candidate = $this->spreadsheet->headerMap($values);
                        if (! $this->spreadsheet->hasRequiredColumns($candidate)) {
                            continue;
                        }
                        $header = $candidate;
                        $sawHeader = true;

                        continue;
                    }

                    $instanceIndex = $header['instance id'] ?? null;
                    if ($instanceIndex !== null && ($values[$instanceIndex] ?? '') !== '') {
                        return;
                    }
                }
            }

            if (! $sawHeader) {
                throw ValidationException::withMessages([
                    'file' => 'Missing catalog columns: '.implode(', ', CatalogSpreadsheet::REQUIRED_COLUMNS).'. Optional column: sku.',
                ]);
            }

            throw ValidationException::withMessages([
                'file' => 'The catalog is empty or has no rows with an Instance ID. If the file has multiple sheets, put the catalog on a sheet that includes an Instance ID column.',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'The catalog could not be read. Upload a valid XLSX or CSV file.',
            ]);
        } finally {
            $reader->close();
        }
    }
}
