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

    expect($result)->toBe(['reports' => [], 'session_finished' => false]);
    Http::assertNothingSent();
});

it('returns no reports when there is nothing reportable, without calling the AI provider', function () {
    Http::fake();

    $result = (new ExecutionReportService)->extractReport('hice sentadilla', [], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe(['reports' => [], 'session_finished' => false]);
    Http::assertNothingSent();
});

it('degrades to no reports when the AI provider fails, without throwing', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $result = (new ExecutionReportService)->extractReport('hice sentadilla 10x40', [['name' => 'Sentadilla']], Tenant::factory()->create(['ai_provider' => 'openai']));

    expect($result)->toBe(['reports' => [], 'session_finished' => false]);
});
