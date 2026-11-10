<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('ops:alerts')->hourly();
Schedule::command('billing:expire-trials')->daily();
// Avant le calcul de la demande : une demande périmée ne doit plus peser sur
// les propositions de réapprovisionnement du jour.
Schedule::command('procurement:expire-stale')->dailyAt('01:45');
Schedule::command('replenishment:compute-demand')->dailyAt('02:00');
