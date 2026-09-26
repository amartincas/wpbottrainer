<?php

use App\Models\Tenant;
use App\Training\Support\ExecutionReportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extract half of the execution-report flow (Hito 6). The LLM's output is
 * never trusted as-is — every field is validated before ExecutionReportService
 * returns it, and anything invalid/hallucinated is dropped rather than
 * defaulted to a guessed value.
 */

function fakeReportExtraction(array $payload): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

it('validates a fully well-formed report with multiple sets', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla',
            'not_performed' => false,
            'sets' => [
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 45, 'duration_seconds' => null],
                ['reps' => 8, 'load' => 50, 'duration_seconds' => null],
            ],
            'rpe_number' => null,
            'rpe_category' => 'hard',
            'note' => null,
            'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport(
        'Sentadilla: 10x40, 10x45, 8x50, me costó bastante',
        [['name' => 'Sentadilla']],
        Tenant::factory()->create(['ai_provider' => 'openai']),
        frontExerciseName: 'Sentadilla',
    );

    expect($result['reports'])->toHaveCount(1);
    expect($result['reports'][0]['exercise_name'])->toBe('Sentadilla');
    expect($result['reports'][0]['sets'])->toHaveCount(3);
    // Hito D (fase D2) — forma aditiva: unit/no_external_load ausentes en
    // la respuesta simulada de la IA se normalizan a null/false, mismo
    // criterio legacy de siempre (sin unidad mencionada = kg implícito).
    expect($result['reports'][0]['sets'][2])->toBe(['reps' => 8, 'load' => 50.0, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false]);
    expect($result['reports'][0]['rpe'])->toBe(8); // "hard" mapeado deterministamente
    expect($result['session_finished'])->toBeFalse();
});

it('discards a set with no quantifiable data at all', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha',
            'not_performed' => false,
            'sets' => [['reps' => null, 'load' => null, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['sets'])->toBe([]);
});

it('discards an out-of-range rpe_number', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha', 'not_performed' => false, 'sets' => [],
            'rpe_number' => 99, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['rpe'])->toBeNull();
});

it('discards an invalid rpe_category instead of guessing a number', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha', 'not_performed' => false, 'sets' => [],
            'rpe_number' => null, 'rpe_category' => 'brutalmente_dificil', 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['rpe'])->toBeNull();
});

it('prefers an explicit rpe_number over a mapped rpe_category', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha', 'not_performed' => false, 'sets' => [],
            'rpe_number' => 9, 'rpe_category' => 'easy', 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['rpe'])->toBe(9);
});

it('parses session_finished and not_performed as booleans', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => true, 'sets' => [],
            'rpe_number' => null, 'rpe_category' => null, 'note' => 'sin tiempo', 'uncertain' => false,
        ]],
        'session_finished' => true,
    ]);

    $result = (new ExecutionReportService)->extractReport('no hice sentadilla, ya me voy', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['not_performed'])->toBeTrue();
    expect($result['session_finished'])->toBeTrue();
});

it('returns no reports for an empty message without calling the AI provider', function () {
    Http::fake();

    $result = (new ExecutionReportService)->extractReport('', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    // Bloque 9 (D052): EMPTY_RESULT ahora incluye, de forma aditiva,
    // safety_signal_text/intents/training_reply — mismo comportamiento de
    // fondo (sin llamar a la IA), contrato ampliado.
    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null, 'requested_focus_terms' => []]);
    Http::assertNothingSent();
});

it('returns no reports when there is nothing reportable, without calling the AI provider', function () {
    Http::fake();

    $result = (new ExecutionReportService)->extractReport('hice sentadilla', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null, 'requested_focus_terms' => []]);
    Http::assertNothingSent();
});

it('degrades to no reports when the AI provider fails, without throwing', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $result = (new ExecutionReportService)->extractReport('hice sentadilla 10x40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null, 'requested_focus_terms' => []]);
});

// ── Hito 8.3: skip_reason + regla de "confirmación sin detalle" ────────

it('validates skip_reason only when not_performed is true', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'no_time',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('no me dio tiempo', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['skip_reason'])->toBe('no_time');
});

it('discards skip_reason when not_performed is false, even if the LLM hallucinates one', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false, 'skip_reason' => 'dont_want',
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('hice sentadilla 10x40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['skip_reason'])->toBeNull();
});

it('discards an invalid skip_reason value instead of trusting the LLM', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'una_razon_inventada',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('no pude', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['skip_reason'])->toBeNull();
});

// El hallazgo real del E2E comercial (Hito 8.3): antes del fix, el prompt no
// tenía ninguna regla para una confirmación sin detalle ("hecho", "listo"),
// lo que probablemente producía "reports": [] — TrainingHandler interpretaba
// eso como "sin señal de reporte" y volvía a generar/reenviar la misma
// sesión. Este test verifica que el CONTRATO ya soporta el caso correcto
// (un reporte con exercise_name null cuando el mensaje es una confirmación
// sin detalle) — la instrucción exacta vive en el prompt, ver
// buildPrompt() y docs/DECISIONS.md.
it('the prompt explicitly instructs that a bare confirmation like "hecho" must still produce a report, never an empty array', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false]);

    (new ExecutionReportService)->extractReport('hecho', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'hecho')
            && str_contains($systemPrompt, 'NUNCA devuelvas "reports": []');
    });
});

it('accepts a report with a null exercise_name and empty sets — the single-pending-exercise case', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => null, 'not_performed' => false, 'skip_reason' => null,
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('hecho', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'])->toHaveCount(1);
    expect($result['reports'][0]['exercise_name'])->toBeNull();
    expect($result['reports'][0]['sets'])->toBe([]);
});

// ── H16.2 Fase 1.3 (auditoría de flujo conversacional, Caso 1A) ─────────
//
// El conteo de series es una tarea de comprensión de lenguaje natural, no
// algo que el código pueda validar contra una fuente de verdad externa (a
// diferencia de RPE, que sí tiene una tabla cerrada) — por eso la garantía
// aquí es de PROMPT (instrucción explícita a la IA), nunca de código. Estos
// tests documentan el contrato del prompt y que el pipeline de validación
// (parseJson/validateSets) sigue sin alterar la cantidad de "sets" que
// llega — nunca pretenden demostrar que el código puede corregir una
// respuesta incorrecta del LLM, porque esa garantía no existe.

it('the prompt explicitly instructs an exact set count matching what the user literally said', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false]);

    (new ExecutionReportService)->extractReport('la serie de 10 con 8kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'CRÍTICO — CONTEO DE SERIES')
            && str_contains($systemPrompt, 'NUNCA completes automáticamente hasta el número de series prescritas')
            && str_contains($systemPrompt, 'NUNCA asumas que el usuario hizo todas sus series');
    });
});

it('"la serie de 10 con 8kg" (singular) is extracted as exactly 1 set — test de extracción, no de persistencia', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 8, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('la serie de 10 con 8kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toHaveCount(1);
    expect($result['reports'][0]['sets'][0])->toBe(['reps' => 10, 'load' => 8.0, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false]);
});

it('"3 series de 10" is extracted as exactly 3 identical sets — test de extracción, no de persistencia', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [
                ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ['reps' => 10, 'load' => null, 'duration_seconds' => null],
            ],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('3 series de 10', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toHaveCount(3);
});

it('an enumeration "10, 10 y 8" is extracted as exactly 3 sets — test de extracción, no de persistencia', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [
                ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ['reps' => 10, 'load' => null, 'duration_seconds' => null],
                ['reps' => 8, 'load' => null, 'duration_seconds' => null],
            ],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10, 10 y 8', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toHaveCount(3);
});

// ── H16.2 Fase 1.3 (corrección post-auditoría E2E) — AM/PM ambiguo ──────
//
// Una prueba E2E real demostró que confiar SOLO en una instrucción de
// prompt para suprimir "reminder_time" no es una garantía suficiente — el
// LLM puede ignorarla y producir una hora igual de "válida" en forma. La
// garantía real ahora es 100% código (ReminderExtractionFields::resolveTime()),
// que revisa el MENSAJE ORIGINAL del usuario, nunca la salida de la IA, en
// busca de un indicador de periodo explícito. Estos tests mockean
// deliberadamente al LLM devolviendo una hora "adivinada" (nunca null) para
// probar que el CÓDIGO la descarta de todos modos — no que el LLM "se
// comportó bien".

it('a genuinely ambiguous bare hour ("a las 7") never produces a usable reminder_time, even if the LLM guesses one anyway', function () {
    fakeReportExtraction([
        'reports' => [], 'session_finished' => false,
        'reminder_time' => '07:00', // el LLM "adivinó" — el código debe descartarlo igual
    ]);

    $result = (new ExecutionReportService)->extractReport('recuérdame a las 7', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reminder_time'])->toBeNull();
});

it('an explicit 24h-format hour ("a las 21") is always preserved, regardless of any period wording', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false, 'reminder_time' => '21:00']);

    $result = (new ExecutionReportService)->extractReport('recuérdame a las 21', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reminder_time'])->toBe('21:00');
});

it('an explicit AM/PM marker ("a las 9 PM") is preserved — the period indicator in the raw message is what unlocks it', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false, 'reminder_time' => '21:00']);

    $result = (new ExecutionReportService)->extractReport('recuérdame a las 9 PM', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reminder_time'])->toBe('21:00');
});

it('"de la mañana" phrasing is preserved as a valid period indicator', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false, 'reminder_time' => '07:00']);

    $result = (new ExecutionReportService)->extractReport('recuérdame a las 7 de la mañana', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reminder_time'])->toBe('07:00');
});

it('"de la noche" phrasing is preserved as a valid period indicator', function () {
    fakeReportExtraction(['reports' => [], 'session_finished' => false, 'reminder_time' => '21:00']);

    $result = (new ExecutionReportService)->extractReport('recuérdame a las 9 de la noche', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reminder_time'])->toBe('21:00');
});

it('validateSets() never inflates or reduces the count returned by the LLM — it only validates ranges per element', function () {
    // Documenta explícitamente que la garantía de conteo es de PROMPT, no de
    // código: si el LLM devolviera un conteo distinto al que el usuario dijo
    // (un fallo de extracción), el pipeline de validación lo deja pasar tal
    // cual — no existe ninguna lógica que cuente palabras del mensaje
    // original ni que corrija la cantidad de elementos.
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [
                ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 8, 'duration_seconds' => null],
            ],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('la serie de 10 con 8kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    // El pipeline de validación copia 1:1 lo que la IA devolvió.
    expect($result['reports'][0]['sets'])->toHaveCount(2);
});

// ══════════════════════════════════════════════════════════════════════
// Hito D (diseño formal v2 aprobado, fase D2) — contrato unit/no_external_load
// ══════════════════════════════════════════════════════════════════════
//
// ExecutionReportService sigue sin conocer Exercise/WorkoutExercise/
// TrackingType/LoadModality — solo extrae y normaliza unidades, NUNCA
// decide si un load es semánticamente válido para un ejercicio (eso es
// D3). `unit`/`no_external_load` son campos ADITIVOS del elemento de
// `sets` ya existente.

it('D2: unit="kg" leaves the load unchanged', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 40, 'unit' => 'kg', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 40 kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0])->toBe(['reps' => 10, 'load' => 40.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false]);
});

it('D2: unit="lb" converts the load to kg', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 10, 'unit' => 'lb', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 10 lb', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['load'])->toBe(4.54); // 10 * 0.453592 = 4.53592 -> HALF_UP a 2 decimales
    expect($result['reports'][0]['sets'][0]['unit'])->toBe('lb');
});

it('D2: 45 lb converts to exactly 20.41 kg, logging the precision rounding (Log::info, sin contexto de ejercicio todavía)', function () {
    Log::spy();

    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 45, 'unit' => 'lb', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 45 lb', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['load'])->toBe(20.41);

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context) => $message === 'TRAINING_LOAD_PRECISION_ROUNDED'
            && $context['original_value'] === 45.0
            && $context['original_unit'] === 'lb'
            && $context['normalized_value'] === 20.41
            // D2 todavía no conoce el ejercicio resuelto — ese contexto lo
            // añade D3, nunca este servicio.
            && ! array_key_exists('workout_session_id', $context)
            && ! array_key_exists('workout_exercise_id', $context)
            && ! array_key_exists('exercise_id', $context)
    );
});

it('D2: unit=null keeps legacy behavior — the number is assumed to already be in kg', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 40, 'unit' => null, 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0])->toBe(['reps' => 10, 'load' => 40.0, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false]);
});

it('D2: unit="unrecognized" discards the load entirely, never guessing kg — the set survives via its other fields', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 20, 'unit' => 'unrecognized', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 20 onzas', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['load'])->toBeNull();
    expect($result['reports'][0]['sets'][0]['unit'])->toBe('unrecognized');
    expect($result['reports'][0]['sets'][0]['reps'])->toBe(10);
});

it('D2: a raw unit value the LLM hallucinates (e.g. "oz", violating the prompt contract) is coerced to unrecognized, never interpreted as kg', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 20, 'unit' => 'oz', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 20 onzas', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['unit'])->toBe('unrecognized');
    expect($result['reports'][0]['sets'][0]['load'])->toBeNull(); // NUNCA 20.0 (equivalente a tratarlo como kg)
});

it('D2: a missing "unit" key (absent from the JSON entirely) defaults to null, same as an explicit null', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 40, 'duration_seconds' => null]], // sin "unit"
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['unit'])->toBeNull();
    expect($result['reports'][0]['sets'][0]['load'])->toBe(40.0);
});

it('D2: no_external_load=true is preserved intact — never converted to 0 or null here, that decision belongs to D3', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 repeticiones sin peso', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['no_external_load'])->toBeTrue();
    expect($result['reports'][0]['sets'][0]['load'])->toBeNull();
});

it('D2: a missing no_external_load key defaults to false — absence of a load mention never implies confirmed zero', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => null, 'duration_seconds' => null]], // sin no_external_load
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 repeticiones', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['no_external_load'])->toBeFalse();
});

it('D2: an explicit numeric load together with no_external_load=true preserves BOTH values — the precedence rule (número > bandera) belongs exclusively to D3', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 10, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => true]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 10 kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['load'])->toBe(10.0);
    expect($result['reports'][0]['sets'][0]['no_external_load'])->toBeTrue();
});

it('D2: a set with ONLY no_external_load=true (no reps/load/duration at all) is never discarded as empty — the signal itself is the data', function () {
    // Hallazgo real de esta fase: la comprobación original de "set vacío"
    // (validateSets(), preexistente) solo miraba reps/load/duration. Sin
    // este ajuste mínimo, "hice 3 series sin peso" (sin ningún número de
    // repeticiones) habría perdido la señal "sin peso" ANTES de llegar a
    // D3 — el propio dato que Hito D existe para preservar. Ajuste
    // deliberadamente exercise-agnostic: no es la "segunda comprobación"
    // de D3 (esa depende de tracking_type/load_modality), es una
    // corrección mínima a la comprobación YA existente para que reconozca
    // la nueva señal como dato real, al mismo nivel que reps/load/duration.
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [
                ['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
                ['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
                ['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
            ],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('hice 3 series sin peso', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toHaveCount(3);
    expect(collect($result['reports'][0]['sets'])->every(fn ($s) => $s['no_external_load'] === true))->toBeTrue();
});

it('regression: a genuinely empty set (no reps/load/duration AND no_external_load=false) is still discarded, exactly as before', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha', 'not_performed' => false,
            'sets' => [['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['sets'])->toBe([]);
});

// ── Regresión: reps/duration/skip_reason/RPE/sets múltiples conviven con
// los campos nuevos sin interferencia ────────────────────────────────────

it('regression: reps validation (out-of-range) is unaffected by the new unit/no_external_load fields', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 1000, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => false]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('1000 repeticiones', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toBe([]); // 1000 fuera de [0,999] -> reps descartado -> set vacío -> descartado
});

it('regression: duration_seconds validation and TimeBased-style sets are unaffected', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Plancha', 'not_performed' => false,
            'sets' => [['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => 60, 'no_external_load' => false]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('60 segundos', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Plancha');

    expect($result['reports'][0]['sets'][0])->toBe(['reps' => null, 'load' => null, 'unit' => null, 'duration_seconds' => 60, 'no_external_load' => false]);
});

it('regression: skip_reason still resolves correctly alongside the new fields', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => true, 'skip_reason' => 'cant_do',
            'sets' => [], 'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('no pude hacerlo', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['skip_reason'])->toBe('cant_do');
});

it('regression: RPE (explicit number) still resolves correctly alongside the new fields', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => 40, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false]],
            'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 40kg, RPE 7', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['rpe'])->toBe(7);
});

it('regression: multiple sets in the same report each carry independent unit/no_external_load values (mixed series, "las dos primeras sin peso y la tercera con 10 kg")', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [
                ['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
                ['reps' => 10, 'load' => null, 'unit' => null, 'duration_seconds' => null, 'no_external_load' => true],
                ['reps' => 10, 'load' => 10, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false],
            ],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('las dos primeras sin peso y la tercera con 10 kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'])->toHaveCount(3);
    expect($result['reports'][0]['sets'][0]['no_external_load'])->toBeTrue();
    expect($result['reports'][0]['sets'][1]['no_external_load'])->toBeTrue();
    expect($result['reports'][0]['sets'][2])->toBe(['reps' => 10, 'load' => 10.0, 'unit' => 'kg', 'duration_seconds' => null, 'no_external_load' => false]);
});

it('D6 (cierre de hueco): an explicit negative load is discarded regardless of unit, never persisted as-is', function () {
    fakeReportExtraction([
        'reports' => [[
            'exercise_name' => 'Sentadilla', 'not_performed' => false,
            'sets' => [['reps' => 10, 'load' => -5, 'unit' => 'kg', 'duration_seconds' => null]],
            'rpe_number' => null, 'rpe_category' => null, 'note' => null, 'uncertain' => false,
        ]],
        'session_finished' => false,
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con -5 kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'][0]['sets'][0]['load'])->toBeNull();
    expect($result['reports'][0]['sets'][0]['reps'])->toBe(10); // el resto del set sobrevive
});

it('regression: a malformed (non-JSON) AI response still degrades to EMPTY_RESULT, unaffected by the new contract fields', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'esto no es json en absoluto']]],
        ], 200),
    ]);

    $result = (new ExecutionReportService)->extractReport('10 con 40kg', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']), frontExerciseName: 'Sentadilla');

    expect($result['reports'])->toBe([]);
});
