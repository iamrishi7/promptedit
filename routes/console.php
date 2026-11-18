<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for missed webhooks and lost jobs (see App\Console\Commands\PollPixelforge). The
// overlap lock expires after 5 minutes: with the default 24 hours, one run killed mid-way (deploy,
// out of memory) would stop polling, abandoning and image copying for a day.
Schedule::command('pixelforge:poll')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Failed jobs are kept a week for debugging, then pruned so the table doesn't grow forever.
Schedule::command('queue:prune-failed --hours=168')->daily()->onOneServer();
