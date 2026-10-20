<?php

namespace Tests\Unit;

use App\Services\Inventory\CatalogUploadValidator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

class CatalogUploadValidatorTest extends TestCase
{
    public function test_empty_and_header_only_catalogs_are_rejected(): void
    {
        foreach ([
            '',
            "Instance ID,Name,Name DE,Attributes,Attributes DE,Main Category\n",
            "sku,stock\nABC,1\n",
        ] as $contents) {
            $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
            file_put_contents($path, $contents);
            try {
                (new CatalogUploadValidator)->validate($path);
                $this->fail('Invalid catalog was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            } finally {
                unlink($path);
            }
        }
    }

    public function test_valid_catalog_without_sku_is_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents(
            $path,
            "Instance ID,Name,Name DE,Attributes,Attributes DE,Main Category\n123,Phone,Telefon,,,Phones\n"
        );
        try {
            (new CatalogUploadValidator)->validate($path);
            $this->assertTrue(true);
        } finally {
            unlink($path);
        }
    }

    public function test_valid_catalog_with_sku_is_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents(
            $path,
            "Instance ID,SKU,Name,Name DE,Attributes,Attributes DE,Main Category\n123,PHONE-SKU,Phone,Telefon,,,Phones\n"
        );
        try {
            (new CatalogUploadValidator)->validate($path);
            $this->assertTrue(true);
        } finally {
            unlink($path);
        }
    }

    public function test_catalog_on_second_sheet_is_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
        @unlink($path);

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        // Sheet 1 empty (matches Refurbed export layout).
        $writer->addRow(Row::fromValues(['']));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(Row::fromValues([
            'Instance ID', 'Name', 'SKU', 'Name DE', 'Attributes', 'Attributes DE', 'Main Category',
        ]));
        $writer->addRow(Row::fromValues([
            444824, 'Phone', 'SKU-1', 'Telefon', 'Brand: X', 'Marke: X', 'Household',
        ]));
        $writer->close();

        try {
            (new CatalogUploadValidator)->validate($path);
            $this->assertTrue(true);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
