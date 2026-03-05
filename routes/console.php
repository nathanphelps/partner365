<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

try {
    $partnersInterval = (int) Setting::get('sync', 'partners_interval_minutes', config('graph.sync_interval_minutes'));
    $guestsInterval = (int) Setting::get('sync', 'guests_interval_minutes', config('graph.sync_interval_minutes'));
} catch (\Throwable) {
    $partnersInterval = (int) config('graph.sync_interval_minutes', 15);
    $guestsInterval = (int) config('graph.sync_interval_minutes', 15);
}

Schedule::command('sync:partners')->cron("*/{$partnersInterval} * * * *");
Schedule::command('sync:guests')->cron("*/{$guestsInterval} * * * *");
