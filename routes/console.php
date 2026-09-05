<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cod2:parse-log')->everyMinute()->withoutOverlapping();
Schedule::command('cod2:recalculate-stats')->everyMinute()->withoutOverlapping();
Schedule::command('demos:reconcile-matches')->everyMinute()->withoutOverlapping();
Schedule::command('cod2:notify-discord-matches')->everyMinute()->withoutOverlapping();

// Avance de mapa de los pugs en curso -- misma cadencia que el resto del
// pipeline, ver AdvancePugMaps para por que no se puede usar ended_at.
Schedule::command('cod2:advance-pug-maps')->everyMinute()->withoutOverlapping();
Schedule::command('cod2:sample-resources')->everyMinute()->withoutOverlapping();
Schedule::command('geoip:update')->monthly();
Schedule::command('demos:prune-old')->daily();
Schedule::command('cod2:prune-resource-samples')->daily();
Schedule::command('backup:run')->dailyAt('03:00');
Schedule::command('hosted-servers:poll')->everyMinute();
Schedule::command('hosted-servers:expire')->everyMinute()->withoutOverlapping();
Schedule::command('players:check-claims')->everyMinute()->withoutOverlapping();
