<?php

namespace App\Training\Support;

/**
 * Hito 10 — validación de los 4 campos de extracción de recordatorio,
 * compartida por `CoachService` y `ExecutionReportService` (mismo criterio
 * que `DetectedIntentType::validateList()`, reutilizado en ambos para no
 * duplicar). Nunca resuelve fecha/hora real — eso es exclusivamente
 * `ReminderTimeResolver`; esto solo garantiza que lo que llega del JSON
 * tiene la forma esperada, descartando en silencio cualquier valor fuera
 * del vocabulario cerrado.
 */
class ReminderExtractionFields
{
    private const VALID_DAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'today', 'tomorrow',
    ];

    /**
     * @return array{reminder_day: ?string, reminder_time: ?string, reminder_recurrence: ?bool, reminder_confirmation: ?bool}
     */
    public static function validate(array $decoded): array
    {
        $day = $decoded['reminder_day'] ?? null;
        $time = $decoded['reminder_time'] ?? null;

        return [
            'reminder_day' => is_string($day) && in_array($day, self::VALID_DAYS, true) ? $day : null,
            'reminder_time' => is_string($time) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) ? $time : null,
            'reminder_recurrence' => is_bool($decoded['reminder_recurrence'] ?? null) ? $decoded['reminder_recurrence'] : null,
            'reminder_confirmation' => is_bool($decoded['reminder_confirmation'] ?? null) ? $decoded['reminder_confirmation'] : null,
        ];
    }
}
