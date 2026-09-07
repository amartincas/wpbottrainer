<?php

use App\Models\Contact;
use App\Models\Reminder;
use App\Models\ReminderSuggestion;
use App\Models\Tenant;
use App\Training\Enums\ReminderStatus;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;

/**
 * Hito 10 — Fase 1: migraciones/modelos/factories. Cobertura de la
 * concurrencia (índice único sobre columna generada) vive aquí porque es
 * una propiedad de las migraciones, no de ninguna clase de dominio.
 */

it('Tenant.timezone defaults to America/Bogota and is never null', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->timezone)->toBe('America/Bogota');
});

it('creates a ReminderSuggestion with proposed_params already resolved, casts working', function () {
    $suggestion = ReminderSuggestion::factory()->create();

    expect($suggestion->origin)->toBe(ReminderSuggestionOrigin::UserRequest);
    expect($suggestion->status)->toBe(ReminderSuggestionStatus::Pending);
    expect($suggestion->proposed_params)->toBe(['day_of_week' => 2, 'time' => '19:00']);
    expect($suggestion->tenant_id)->toBe($suggestion->contact->tenant_id);
});

it('creates a Reminder with recurrence and correct casts', function () {
    $reminder = Reminder::factory()->create();

    expect($reminder->status)->toBe(ReminderStatus::Pending);
    expect($reminder->recurrence)->toBe(['freq' => 'weekly', 'day_of_week' => 2, 'time' => '19:00']);
    expect($reminder->tenant_id)->toBe($reminder->contact->tenant_id);
});

// ── Concurrencia: índice único sobre columna generada (nivel 1, directo) ──

it('the database rejects a second pending Reminder for the same contact', function () {
    $contact = Contact::factory()->create();
    Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'status' => ReminderStatus::Pending]);

    expect(fn () => Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'status' => ReminderStatus::Sending]))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect(Reminder::where('contact_id', $contact->id)->count())->toBe(1);
});

it('a cancelled/sent/failed Reminder never blocks a new active one for the same contact', function () {
    $contact = Contact::factory()->create();
    Reminder::factory()->cancelled()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    Reminder::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id, 'status' => ReminderStatus::Pending]);

    expect(Reminder::where('contact_id', $contact->id)->count())->toBe(2);
});

it('the database rejects a second pending ReminderSuggestion for the same contact', function () {
    $contact = Contact::factory()->create();
    ReminderSuggestion::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    expect(fn () => ReminderSuggestion::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('a declined/accepted/expired ReminderSuggestion never blocks a new pending one for the same contact', function () {
    $contact = Contact::factory()->create();
    ReminderSuggestion::factory()->declined()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    ReminderSuggestion::factory()->create(['contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id]);

    expect(ReminderSuggestion::where('contact_id', $contact->id)->count())->toBe(2);
});

// ── Concurrencia: nivel 2 ──
//
// NOTA DE DISEÑO: una prueba de concurrencia real (dos conexiones físicas
// separadas, ambas confirmando de verdad al motor) no es confiablemente
// implementable bajo `RefreshDatabase` — este proyecto envuelve CADA test en
// una transacción sobre la conexión por defecto que se revierte al final;
// una segunda conexión física a la misma base de datos no vería esas filas
// como comprometidas y el resultado sería un falso positivo (o dejaría
// datos huérfanos si de verdad comprometiera algo, ya que esa segunda
// conexión queda fuera del rollback automático). Se documenta esta
// limitación explícitamente en vez de entregar una prueba frágil o
// engañosa. El nivel 1 de arriba ya demuestra la garantía real: el
// constraint de base de datos rechaza el duplicado sin importar qué haga o
// deje de hacer el código de aplicación — exactamente la propiedad que
// protege frente a dos requests simultáneos en producción (dos conexiones
// reales, sin la envoltura de RefreshDatabase, sí ven los commits entre sí).
