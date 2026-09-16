<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hito 10 (Reminders, D053) — `Schedule::command()` por sí solo NO hace
// nada si nada invoca `php artisan schedule:run` cada minuto. Corrección
// post-revisión: ya existe un contenedor `scheduler` dedicado en
// docker-compose.yml (misma imagen que `app`/`queue`, sin build propio)
// ejecutando exactamente `while true; do php artisan schedule:run; sleep 60;
// done` — no hace falta ningún cron/supervisor adicional. Ese contenedor
// SOLO invoca `schedule:run`; nunca procesa jobs directamente — el envío
// real de `SendReminderJob` lo sigue haciendo el contenedor `queue`, ya
// existente y sin cambios.
Schedule::command('reminders:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('reminders:recover-stuck')->everyFiveMinutes()->withoutOverlapping();

// Hito 11 (D2) — mismo contenedor `scheduler` de Hito 10, sin infraestructura
// nueva. Cierra un gap real: `PaymentStatus::Expired` existía pero nada lo
// asignaba nunca (ver App\Console\Commands\ExpireStalePayments).
Schedule::command('payments:expire-stale')->everyFiveMinutes()->withoutOverlapping();

// P1-A (Nudge por ejercicio no reportado) — mismo contenedor `scheduler`,
// mismo mecanismo, sin infraestructura nueva. `everyMinute()` porque el
// umbral (`Tenant.exercise_nudge_after_minutes`) es configurable por tenant
// y puede ser tan bajo como unos pocos minutos — una cadencia más lenta
// retrasaría el nudge para tenants con umbrales cortos. `withoutOverlapping()`
// evita que dos ejecuciones programadas de este comando corran a la vez
// (ver App\Console\Commands\NudgeUnreportedExercises).
Schedule::command('training:nudge-unreported-exercises')->everyMinute()->withoutOverlapping();
