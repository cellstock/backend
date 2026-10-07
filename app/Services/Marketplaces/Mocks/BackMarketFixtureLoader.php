<?php

namespace App\Services\Marketplaces\Mocks;

use JsonException;
use RuntimeException;

class BackMarketFixtureLoader
{
    public function orders(): array
    {
        return $this->load('orders-page-1.json');
    }

    public function listings(): array
    {
        return $this->load('listings-page-1.json');
    }

    private function load(string $filename): array
    {
        $path = resource_path("mocks/back-market/{$filename}");

        if (! is_file($path)) {
            throw new RuntimeException(
                "Back Market fixture not found: {$filename}"
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                "Unable to read Back Market fixture: {$filename}"
            );
        }

        try {
            return json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid Back Market fixture JSON: {$filename}",
                previous: $exception
            );
        }
    }
}