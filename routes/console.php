<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('sunat_worker.enabled', true)) {
    Schedule::command('sunat:dispatch-pending --quiet')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(5);
}
