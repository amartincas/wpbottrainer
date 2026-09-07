# Runbook — Verificación E2E real de Reminders en staging (Hito 10, D053)

> Checklist exacto para comprobar en staging que un `Reminder` se dispara **sin ninguna intervención manual** — el contenedor `scheduler` invoca `schedule:run` por su cuenta, `reminders:dispatch-due` encola el envío, y el contenedor `queue` (ya existente, sin cambios) lo entrega. Este documento describe el procedimiento; **no se ejecuta en esta tarea** — queda listo para cuando se decida correrlo contra staging real.

## 0. Qué ya está listo (verificado en este hito, sin desplegar)

- ✅ `docker-compose.yml` tiene un servicio `scheduler` (misma imagen que `app`/`queue`, sin build propio) ejecutando `while true; do php artisan schedule:run; sleep 60; done`.
- ✅ `routes/console.php` registra `reminders:dispatch-due` (cada minuto) y `reminders:recover-stuck` (cada 5 minutos).
- ✅ Cadena completa probada de extremo a extremo en tests automatizados (`ReminderSchedulerTest.php` — *"end-to-end: reminders:dispatch-due ALONE..."*): `reminders:dispatch-due` → `SendReminderJob` (vía cola real, no invocado directamente) → `TrainingReminderExecutor` → `CustomerNotifier` → `WhatsAppMessage.dispatch_confirmed_at`. La única pieza que ese test no puede cubrir es que el propio `schedule:run` se dispare solo, cada minuto, sin que nadie lo invoque — eso exige un contenedor real corriendo, de ahí este runbook.

## 1. Desplegar el contenedor `scheduler`

```bash
# En el VPS de staging, dentro del directorio del proyecto
docker compose up -d scheduler
docker compose ps scheduler   # debe verse "healthy" pasados ~40s (start_period + primeros ciclos)
docker compose logs -f scheduler   # confirma que no hay errores de arranque
```

No requiere migración ni cambio de `.env` — reutiliza la imagen ya construida para `app`/`queue`.

## 2. Crear un `Reminder` sintético con `fire_at` cercano

Vía Tinker dentro del contenedor `app` (nunca datos de un Tenant/Contact real de producción):

```bash
docker compose exec app php artisan tinker
```

```php
$tenant = \App\Models\Tenant::factory()->create(['wa_access_token' => '<token real de prueba>', 'wa_phone_number_id' => '<phone_number_id real de prueba>']);
$contact = \App\Models\Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '<tu número de prueba, con código de país>']);
\App\Models\Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => $contact->customer_phone, 'last_session_at' => now()]); // abre la ventana de 24h (mensaje libre, sin plantilla)
$reminder = \App\Models\Reminder::factory()->oneOff()->create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'fire_at' => now()->addMinutes(2)]);
$reminder->id
```

> Usa credenciales reales de un número de prueba de Meta (mismo criterio que `docs/E2E_META_RUNBOOK.md`) — sin eso, `CustomerNotifier` llegará a intentar el envío pero Meta lo rechazará.

## 3. Esperar, sin ejecutar nada manualmente

Espera 2-3 minutos (nunca ejecutes `artisan reminders:dispatch-due` a mano — el objetivo es demostrar que el `scheduler` lo hace solo).

## 4. Verificar

```bash
# a) El WhatsApp de prueba debe recibir el mensaje del recordatorio.

# b) Logs — busca REMINDER_SENT (nunca REMINDER_CLAIM_SKIPPED repetido, señal de que dispatch-due nunca corrió)
docker compose logs app queue scheduler | grep REMINDER_SENT

# c) Estado del Reminder — debe haber transicionado de "pending" a "sent" solo
docker compose exec app php artisan tinker --execute="dd(\App\Models\Reminder::find($reminder->id)->status);"

# d) WhatsAppMessage con dispatch_confirmed_at real (no null) — la prueba definitiva de idempotencia funcionando
docker compose exec app php artisan tinker --execute="dd(\App\Models\WhatsAppMessage::where('idempotency_key', \App\Models\Reminder::find($reminder->id)->currentOccurrenceIdempotencyKey())->first()?->dispatch_confirmed_at);"
```

Éxito = las 4 verificaciones (a-d) positivas, sin haber ejecutado ningún comando `artisan reminders:*` manualmente en ningún momento del procedimiento.

## 5. Limpieza

Borra el `Tenant`/`Contact`/`Reminder` sintéticos creados en el paso 2 — son datos de prueba, nunca deben quedar en la base de datos de staging de forma permanente.

```php
$reminder->delete();
$contact->delete();
$tenant->delete();
```
