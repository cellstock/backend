<?php
namespace Tests\Unit;
use App\Services\Inventory\CatalogUploadValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class CatalogUploadValidatorTest extends TestCase
{
    public function test_empty_and_header_only_catalogs_are_rejected(): void
    {
        foreach (['', "Instance ID,Name,Name DE,Attributes,Attributes DE,Main Category\n", "sku,stock\nABC,1\n"] as $contents) {
            $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
            file_put_contents($path, $contents);
            try {
                (new CatalogUploadValidator)->validate($path);
                $this->fail('Invalid catalog was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            } finally { unlink($path); }
        }
    }
    public function test_valid_catalog_is_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents($path, "Instance ID,Name,Name DE,Attributes,Attributes DE,Main Category\n123,Phone,Telefon,,,Phones\n");
        try { (new CatalogUploadValidator)->validate($path); $this->assertTrue(true); }
        finally { unlink($path); }
    }
}
