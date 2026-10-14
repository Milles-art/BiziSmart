<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('products:cleanup-images')->hourly()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('pricing:reconcile')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('expenses:cleanup-photos')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('bizismart:loss-alerts')->everyFiveMinutes()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('messaging:dispatch')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('messaging:reconcile-sms')->everyFiveMinutes()->withoutOverlapping();
