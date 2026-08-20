<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cod2:parse-log')->everyMinute()->withoutOverlapping();
Schedule::command('demos:reconcile-matches')->everyMinute()->withoutOverlapping();
Schedule::command('geoip:update')->monthly();
Schedule::command('demos:prune-old')->daily();
