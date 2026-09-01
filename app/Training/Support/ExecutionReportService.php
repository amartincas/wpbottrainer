<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\RpeCategory;
use App\Training\Enums\SkipReason;
use Illuminate\Support\Facades\Log;

/**
 * Extract half of the execution-report flow (Hito 6) — turns free text
 * ("Terminé las 3 series, hice 10, 10 y 8 con 40 kg. Me costó bastante.")
 * into candidate structured values. Every value is validated here before
 * TrainingHandler ever persists anything — an invalid/hallucinated value is
 * dropped, never trusted as-is. The LLM never writes to ExerciseLog/
 * ExerciseSet directly, and never invents a value the user did not mention:
 * a set with no reps/load/duration at all is discarded rather than
 * defaulted to zero or to the prescription.
 *
 * RPE is the clearest example of "LLM signals, code decides": the LLM may
 * only classify the user's qualitative language into one of a fixed set of
 * categories (App\Training\Enums\RpeCategory) — mapCategoryToRpe() is the
 * deterministic table that turns that into a number, never the LLM itself.
 * An explicit number the user states directly ("le doy un 8") is accepted
 * too, but still validated to the 1-10 range like any other field.
 */
class ExecutionReportService
{
    /**
     * Deterministic mapping from a qualitative RPE category to a numeric
     * RPE (1-10 scale). This table — not the LLM — is the only thing that
     * turns "estuvo pesado" into a number.
     */
    private const RPE_CATEGORY_MAP = [
        'very_easy' => 3,
        'easy' => 5,
        'moderate' => 6,
        'hard' => 8,
        'very_hard' => 10,
    ];

    private const EMPTY_RESULT = ['reports' => [], 'session_finished' => false];

    /**
     * @param array<int, array{name: string}> $reportableExercises exercises
     *        still unreported in the active session — the LLM may only name
     *        one of these; anything else is treated as unresolved.
     * @return array{reports: array<int, array{
     *     exercise_name: ?string, not_performed: bool, skip_reason: ?string,
     *     sets: array<int, array{reps: ?int, load: ?float, duration_seconds: ?int}>,
     *     rpe: ?int, note: ?string, uncertain: bool,
     * }>, session_finished: bool}
     */
    public function extractReport(string $messageBody, array $reportableExercises, Tenant $tenant): array
    {
        if (trim($messageBody) === '' || $reportableExercises === []) {
            return self::EMPTY_RESULT;
        }

        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($messageBody, $this->buildPrompt($reportableExercises), []);

            return $this->parseJson($raw);
        } catch (\Throwable $e) {
            Log::warning('TRAINING_REPORT_EXTRACTION_ERROR', ['error' => $e->getMessage()]);

            return self::EMPTY_RESULT;
        }
    }

    private function buildPrompt(array $reportableExercises): string
    {
        $names = json_encode(array_map(fn ($e) => $e['name'], $reportableExercises));

        return <<<PROMPT
Eres un asistente que EXTRAE de un mensaje de WhatsApp lo que un usuario reporta haber ejecutado de un entrenamiento. NUNCA inventes un valor que el usuario no mencionó explícitamente.

Ejercicios que el usuario podría estar reportando (debes usar el nombre EXACTO de esta lista, o null si no puedes determinar a cuál se refiere): {$names}

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown) con esta forma exacta:
{
  "reports": [
    {
      "exercise_name": "<uno de los nombres de la lista>" | null,
      "not_performed": true | false,
      "skip_reason": "cant_do"|"dont_want"|"no_time"|"other" (SOLO si not_performed=true y el usuario dio o insinuó una razón, ej. "no pude" → cant_do, "no quiero" → dont_want, "no me dio tiempo" → no_time) | null,
      "sets": [{"reps": <entero>|null, "load": <número>|null, "duration_seconds": <entero>|null}, ...],
      "rpe_number": <entero 1-10 si el usuario dio un número explícito de esfuerzo> | null,
      "rpe_category": "very_easy"|"easy"|"moderate"|"hard"|"very_hard" (SOLO si el usuario describió el esfuerzo con palabras, ej. "fácil", "pesado", "muy difícil") | null,
      "note": "<observación textual relevante no cubierta arriba>" | null,
      "uncertain": true (si el usuario usó lenguaje de duda: "creo que", "más o menos", "unas", "tal vez") | false
    }
  ],
  "session_finished": true (si el usuario indica que terminó/cerró toda la sesión, ej. "eso fue todo", "ya terminé") | false
}

Reglas:
- Un elemento de "sets" por cada serie que el usuario mencionó explícitamente. Si dice "3 series de 10 con 40kg" sin variación, genera 3 elementos idénticos {"reps":10,"load":40,"duration_seconds":null}.
- Si el usuario no da NINGÚN número de series/repeticiones/carga/duración para un ejercicio, "sets" debe ser un arreglo vacío [] — nunca inventes un valor.
- "not_performed": true solo si el usuario dice explícitamente que NO hizo ese ejercicio (incluye tanto "no pude" como "no quiero" — la diferencia va en "skip_reason", no en este campo).
- Puedes incluir más de un elemento en "reports" si el mensaje cubre varios ejercicios.
- IMPORTANTE: una confirmación breve sin ningún detalle (ej. "hecho", "listo", "ya", "terminado", "list") SIGUE siendo un reporte real, no un mensaje vacío — genera UN elemento en "reports" para ese caso, con "exercise_name": null (deja que el sistema determine a cuál ejercicio se refiere), "not_performed": false, "sets": [], y todo lo demás null. NUNCA devuelvas "reports": [] para una confirmación de este tipo.
- Si el mensaje genuinamente no tiene ninguna relación con el entrenamiento (ej. cambia de tema por completo), "reports" debe ser [].
PROMPT;
    }

    private function parseJson(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded) || ! is_array($decoded['reports'] ?? null)) {
            return self::EMPTY_RESULT;
        }

        $reports = [];

        foreach ($decoded['reports'] as $report) {
            if (! is_array($report)) {
                continue;
            }

            $notPerformed = (bool) ($report['not_performed'] ?? false);

            $reports[] = [
                'exercise_name' => is_string($report['exercise_name'] ?? null) ? $report['exercise_name'] : null,
                'not_performed' => $notPerformed,
                'skip_reason' => $notPerformed ? $this->validateSkipReason($report['skip_reason'] ?? null) : null,
                'sets' => $this->validateSets($report['sets'] ?? null),
                'rpe' => $this->resolveRpe($report),
                'note' => is_string($report['note'] ?? null) && $report['note'] !== '' ? $report['note'] : null,
                'uncertain' => (bool) ($report['uncertain'] ?? false),
            ];
        }

        return [
            'reports' => $reports,
            'session_finished' => (bool) ($decoded['session_finished'] ?? false),
        ];
    }

    private function validateSets(mixed $sets): array
    {
        if (! is_array($sets)) {
            return [];
        }

        $validated = [];

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            $reps = $this->validateIntRange($set['reps'] ?? null, 0, 999);
            $load = $this->validateNumericRange($set['load'] ?? null, 0, 999);
            $duration = $this->validateIntRange($set['duration_seconds'] ?? null, 0, 7200);

            // Una serie sin ningún dato cuantificable no aporta nada — se
            // descarta en vez de guardarse vacía.
            if ($reps === null && $load === null && $duration === null) {
                continue;
            }

            $validated[] = ['reps' => $reps, 'load' => $load, 'duration_seconds' => $duration];
        }

        return $validated;
    }

    private function validateSkipReason(mixed $value): ?string
    {
        return is_string($value) ? SkipReason::tryFrom($value)?->value : null;
    }

    private function resolveRpe(array $report): ?int
    {
        $explicit = $this->validateIntRange($report['rpe_number'] ?? null, 1, 10);

        if ($explicit !== null) {
            return $explicit;
        }

        $category = is_string($report['rpe_category'] ?? null) ? RpeCategory::tryFrom($report['rpe_category']) : null;

        return $category !== null ? $this->mapCategoryToRpe($category) : null;
    }

    private function mapCategoryToRpe(RpeCategory $category): int
    {
        return self::RPE_CATEGORY_MAP[$category->value];
    }

    private function validateIntRange(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_numeric($value) && (float) $value === floor((float) $value)) {
            $int = (int) $value;
        } else {
            return null;
        }

        return ($int >= $min && $int <= $max) ? $int : null;
    }

    private function validateNumericRange(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return ($float >= $min && $float <= $max) ? $float : null;
    }
}
