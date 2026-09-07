<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hito 10 (Reminders) — IMPORTANTE: `Schedule::command()` por sí solo NO
// hace nada en Docker si nada invoca `php artisan schedule:run` cada
// minuto. Este proyecto no tenía, antes de este hito, ningún cron/supervisor
// corriendo esa entrada — debe agregarse explícitamente al despliegue (cron
// del contenedor `app`, o un contenedor `scheduler` dedicado ejecutando
// `while true; do php artisan schedule:run; sleep 60; done`, o el
// equivalente de Laravel Forge/Sail). Sin eso, ningún Reminder se disparará
// jamás, sin importar cuán correcto sea el código de abajo.
Schedule::command('reminders:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('reminders:recover-stuck')->everyFiveMinutes()->withoutOverlapping();
