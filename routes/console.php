<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Support\Instance\Heartbeats;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The two heartbeats that `snapsbrew:status` and `snapsbrew:doctor` read. The
| scheduler writes its own beat, and a queue worker writes the queue beat.
*/
Schedule::call(fn () => Heartbeats::beat(Heartbeats::SCHEDULER))
    ->name('snapsbrew:scheduler-heartbeat')
    ->everyMinute();

Schedule::job(new RecordQueueHeartbeat)
    ->name('snapsbrew:queue-heartbeat')
    ->everyMinute();
