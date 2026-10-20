<?php

namespace App\Services\Inventory;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class CatalogSpreadsheet
{
    public const REQUIRED_COLUMNS = [
        'instance id',
        'name',
        'name de',
        'attributes',
        'attributes de',
        'main category',
    ];

    public function reader(string $path): ReaderInterface
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv'
            ? new CsvReader
            : new XlsxReader;
    }

    public function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return floor($value) == $value
                ? (string) (int) $value
                : rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return trim((string) $value);
    }

    public function values(Row $row): array
    {
        return array_map(fn (mixed $value): string => $this->cellString($value), $row->toArray());
    }

    public function normalizeHeaderName(string $value): string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', $value), "\xEF\xBB\xBF "));
        $key = str_replace(['_', '-'], ' ', $key);

        return match ($key) {
            'instanceid', 'instance' => 'instance id',
            'namede' => 'name de',
            'attributesde' => 'attributes de',
            'maincategory', 'main categorie', 'category' => 'main category',
            default => $key,
        };
    }

    /**
     * @return array<string, int> column name => index
     */
    public function headerMap(array $values): array
    {
        $header = [];
        foreach ($values as $index => $value) {
            $name = $this->normalizeHeaderName((string) $value);
            if ($name === '' || isset($header[$name])) {
                continue;
            }
            $header[$name] = (int) $index;
        }

        return $header;
    }

    public function hasRequiredColumns(array $headerMap): bool
    {
        return array_diff(self::REQUIRED_COLUMNS, array_keys($headerMap)) === [];
    }

    public function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
