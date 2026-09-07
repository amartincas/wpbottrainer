<?php

use App\Models\Tenant;
use App\Training\Support\ExecutionReportService;
use Illuminate\Support\Facades\Http;

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
    );

    expect($result['reports'])->toHaveCount(1);
    expect($result['reports'][0]['exercise_name'])->toBe('Sentadilla');
    expect($result['reports'][0]['sets'])->toHaveCount(3);
    expect($result['reports'][0]['sets'][2])->toBe(['reps' => 8, 'load' => 50.0, 'duration_seconds' => null]);
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

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('algo', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('no hice sentadilla, ya me voy', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['reports'][0]['not_performed'])->toBeTrue();
    expect($result['session_finished'])->toBeTrue();
});

it('returns no reports for an empty message without calling the AI provider', function () {
    Http::fake();

    $result = (new ExecutionReportService)->extractReport('', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

    // Bloque 9 (D052): EMPTY_RESULT ahora incluye, de forma aditiva,
    // safety_signal_text/intents/training_reply — mismo comportamiento de
    // fondo (sin llamar a la IA), contrato ampliado.
    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null]);
    Http::assertNothingSent();
});

it('returns no reports when there is nothing reportable, without calling the AI provider', function () {
    Http::fake();

    $result = (new ExecutionReportService)->extractReport('hice sentadilla', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null]);
    Http::assertNothingSent();
});

it('degrades to no reports when the AI provider fails, without throwing', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $result = (new ExecutionReportService)->extractReport('hice sentadilla 10x40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe(['reports' => [], 'session_finished' => false, 'safety_signal_text' => null, 'intents' => [], 'training_reply' => null, 'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null]);
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

    $result = (new ExecutionReportService)->extractReport('no me dio tiempo', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('hice sentadilla 10x40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('no pude', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    (new ExecutionReportService)->extractReport('hecho', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

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

    $result = (new ExecutionReportService)->extractReport('hecho', [['name' => 'Plancha']], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result['reports'])->toHaveCount(1);
    expect($result['reports'][0]['exercise_name'])->toBeNull();
    expect($result['reports'][0]['sets'])->toBe([]);
});
