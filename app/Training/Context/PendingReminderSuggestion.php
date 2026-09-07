<?php

namespace App\Training\Context;

/**
 * Hito 10 (D053, corrección post-revisión) — hecho estructurado que expone
 * la `ReminderSuggestion` pendiente del contacto (si existe) dentro de
 * `CoachContext`, para que `reminder_confirmation` se identifique SIN
 * depender de que el mensaje original de la oferta siga dentro de la
 * ventana de `recentMessages` (10 mensajes) — una `ReminderSuggestion`
 * puede seguir `pending` hasta 24h, muchas más que esa ventana.
 *
 * Deliberadamente solo los 3 campos que la IA necesita para redactar/
 * reconocer la propuesta en lenguaje natural — nunca `tenant_id`/
 * `contact_id`/`id`/`status`, que son detalles de persistencia sin ningún
 * valor para el prompt. `day`/`time` ya vienen del vocabulario cerrado de
 * `ReminderExtractionFields` (nunca texto libre) — ver `ReminderSuggestion.
 * proposed_params`.
 */
final readonly class PendingReminderSuggestion
{
    public function __construct(
        public ?string $day,
        public ?string $time,
        public bool $recurring,
    ) {}
}
