<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('documents:sync')
    ->everyFiveMinutes()
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('facturanet.processing.scheduler_enabled', false));
