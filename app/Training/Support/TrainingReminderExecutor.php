<?php

namespace App\Training\Support;

use App\Core\Notifications\CustomerNotifier;
use App\Core\Reminders\ReminderExecutorInterface;
use App\Models\Reminder;
use Carbon\CarbonImmutable;

/**
 * Hito 10 — ejecuta un `Reminder` de tipo `training_weekly`/`training_one_off`.
 * El CÓDIGO decide aquí qué significa ejecutarlo: es una invitación a
 * continuar el flujo `continue_training` ya existente de Bloque 9 (nunca un
 * mensaje libre sin propósito) — la IA (`ReminderMessageComposer`) solo
 * redacta CÓMO se dice, a partir de hechos ya resueltos aquí.
 *
 * Nunca pasa por `TrainingHandler`/`ConversationTurnResolver` — es un
 * evento proactivo iniciado por el sistema, no una respuesta a un mensaje
 * del usuario (D052/D053). La continuidad posterior (qué pasa cuando el
 * usuario responde) es responsabilidad de `Reminder.awaiting_response_until`
 * + `TrainingIntentClassifier`/`TrainingHandler`, no de esta clase.
 */
class TrainingReminderExecutor implements ReminderExecutorInterface
{
    /**
     * // DECISIÓN DE NEGOCIO PENDIENTE — valor técnico provisional. Ventana
     * de continuidad conversacional tras un envío exitoso (ver D053: nunca
     * es parte de Reminder.status).
     */
    private const AWAITING_RESPONSE_HOURS = 2;

    private const EVENT_KEY = 'training_reminder';

    private const WEEKDAY_NAMES = [
        0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles',
        4 => 'jueves', 5 => 'viernes', 6 => 'sábado',
    ];

    public function __construct(
        private readonly TimezoneResolver $timezoneResolver,
        private readonly ReminderMessageComposer $composer,
        private readonly CustomerNotifier $notifier,
    ) {}

    public function execute(Reminder $reminder): bool
    {
        $contact = $reminder->contact;
        $tenant = $reminder->tenant;
        $timezone = $this->timezoneResolver->resolve($contact);

        $facts = $this->buildFacts($reminder, $timezone);
        $text = $this->composer->compose($facts, $tenant);

        $result = $this->notifier->notify(
            $tenant,
            $contact->customer_phone,
            self::EVENT_KEY,
            [],
            $text,
            $reminder->currentOccurrenceIdempotencyKey(),
        );

        if ($result->confirmed) {
            // Ventana de continuidad conversacional — NUNCA toca status
            // (D053). Se fija solo tras una entrega confirmada.
            $reminder->update(['awaiting_response_until' => now()->addHours(self::AWAITING_RESPONSE_HOURS)]);
        }

        return $result->confirmed;
    }

    /**
     * Hechos ESTRUCTURADOS y confiables — nunca CoachContext, nunca
     * historial de conversación. `expected_action` fija la semántica
     * (continue_training) que la IA nunca puede cambiar.
     *
     * @return array{reminder_type: string, local_date: string, local_time: string, recurrence_description: ?string, training_context: string, expected_action: string}
     */
    private function buildFacts(Reminder $reminder, string $timezone): array
    {
        $fireAtLocal = CarbonImmutable::instance($reminder->fire_at)->setTimezone($timezone);
        $nowLocal = CarbonImmutable::now($timezone);

        return [
            'reminder_type' => $reminder->type,
            'local_date' => $fireAtLocal->isSameDay($nowLocal) ? 'hoy' : $fireAtLocal->format('d/m/Y'),
            'local_time' => $fireAtLocal->format('H:i'),
            'recurrence_description' => $this->describeRecurrence($reminder->recurrence),
            'training_context' => 'recordatorio de entrenamiento',
            'expected_action' => 'continue_training',
        ];
    }

    private function describeRecurrence(?array $recurrence): ?string
    {
        if ($recurrence === null) {
            return null;
        }

        $dayName = self::WEEKDAY_NAMES[$recurrence['day_of_week']] ?? null;

        return $dayName !== null
            ? "todos los {$dayName} a las {$recurrence['time']}"
            : null;
    }
}
