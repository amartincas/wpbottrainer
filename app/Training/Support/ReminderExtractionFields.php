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
 *
 * H16.2 Fase 1.3 (corrección post-auditoría E2E) — "reminder_time" ya NO se
 * confía tal cual venga de la IA cuando cae en el rango 1-12h (formato de
 * reloj de 12 horas, genuinamente ambiguo sin AM/PM): antes de este fix, la
 * única defensa era una instrucción de prompt ("no adivines AM/PM"), y una
 * prueba E2E real demostró que un LLM puede ignorarla y producir una hora
 * igual de "válida" en forma (ej. "09:00") sin que el código tuviera forma
 * de distinguirla de una hora realmente dada por el usuario.
 *
 * La corrección NO agrega un campo nuevo que la IA deba autoevaluar (eso
 * solo cambiaría una bandera de prompt por otra igual de confiada al LLM,
 * con el mismo riesgo de que se ignore). En su lugar, el propio CÓDIGO
 * revisa el MENSAJE ORIGINAL del usuario (nunca la salida de la IA) en
 * busca de un indicador de periodo explícito — am/pm, "de la mañana/tarde/
 * noche", "mediodía"/"medianoche" — y solo entonces confía en la hora que
 * la IA compuso. Una hora ya en formato 24h inequívoco (00, o 13-23) nunca
 * necesita este chequeo: es inequívoca por construcción matemática, sin
 * importar el texto del mensaje.
 */
class ReminderExtractionFields
{
    private const VALID_DAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'today', 'tomorrow',
    ];

    /**
     * Formato HH:MM válido en general (00-23).
     */
    private const TIME_FORMAT_REGEX = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    /**
     * Subconjunto de TIME_FORMAT_REGEX que corresponde al reloj de 12 horas
     * (01-12h) — el único rango genuinamente ambiguo sin un indicador de
     * periodo explícito. "00" (medianoche) y "13"-"23" son formato 24h
     * inequívoco por construcción, nunca entran aquí.
     */
    private const AMBIGUOUS_HOUR_REGEX = '/^(0[1-9]|1[0-2]):[0-5]\d$/';

    /**
     * Indicadores de periodo que el USUARIO debe haber escrito literalmente
     * en su mensaje para que una hora 1-12h se considere resuelta. Nunca se
     * evalúa contra la salida de la IA — siempre contra el texto original.
     * "am"/"pm" usa un lookaround en vez de \b porque \b no separa dígito+
     * letra ("9pm" no tiene límite de palabra entre "9" y "p") — así se
     * reconoce tanto "9pm"/"9PM" pegado como "9 pm"/"9 p.m." con espacio.
     */
    private const PERIOD_INDICATOR_PATTERN = '/(?<![a-záéíóúñ])(a\.?m\.?|p\.?m\.?)(?![a-záéíóúñ])|(?:(?:de|en|por)\s+la|esta)\s+(?:mañana|manana|tarde|noche)|mediod[ií]a|medianoche/iu';

    /**
     * @param  string  $messageBody  el mensaje ORIGINAL del usuario para este
     *         turno — única fuente de verdad para decidir si hubo un
     *         indicador de periodo explícito. Nunca se usa para nada más
     *         aquí (no se re-extrae día/recurrencia/confirmación de él).
     * @return array{reminder_day: ?string, reminder_time: ?string, reminder_recurrence: ?bool, reminder_confirmation: ?bool}
     */
    public static function validate(array $decoded, string $messageBody = ''): array
    {
        $day = $decoded['reminder_day'] ?? null;

        return [
            'reminder_day' => is_string($day) && in_array($day, self::VALID_DAYS, true) ? $day : null,
            'reminder_time' => self::resolveTime($decoded['reminder_time'] ?? null, $messageBody),
            'reminder_recurrence' => is_bool($decoded['reminder_recurrence'] ?? null) ? $decoded['reminder_recurrence'] : null,
            'reminder_confirmation' => is_bool($decoded['reminder_confirmation'] ?? null) ? $decoded['reminder_confirmation'] : null,
        ];
    }

    /**
     * Determinista: nunca decide "es AM o PM" (eso ya lo hizo, o no, el
     * usuario) — solo decide si hay evidencia suficiente en el mensaje
     * original para confiar en la hora que la IA compuso. Sin esa
     * evidencia, la hora se descarta (null) sin importar qué haya puesto la
     * IA — el mismo criterio de "nunca confiar en un valor no verificable"
     * ya usado para RPE/skip_reason en ExecutionReportService.
     */
    private static function resolveTime(mixed $value, string $messageBody): ?string
    {
        if (! is_string($value) || ! preg_match(self::TIME_FORMAT_REGEX, $value)) {
            return null;
        }

        if (! preg_match(self::AMBIGUOUS_HOUR_REGEX, $value)) {
            // "00" o "13"-"23" — formato 24h inequívoco por construcción.
            return $value;
        }

        return preg_match(self::PERIOD_INDICATOR_PATTERN, $messageBody) === 1 ? $value : null;
    }
}
