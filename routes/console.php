<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('marketplaces:sync-enabled')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('refurbed:sync-reference-data')
    ->everyTenMinutes()
    ->withoutOverlapping();
