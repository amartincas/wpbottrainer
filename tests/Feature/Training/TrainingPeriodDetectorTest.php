<?php

use App\Training\Support\TrainingPeriodDetector;

/**
 * Hito — Historial de progreso por período. Determinista, sin IA — cubre
 * ÚNICAMENTE frases inequívocas (ver docblock de la clase). "el último
 * mes"/"en general" fueron EXCLUIDAS deliberadamente del diseño aprobado
 * (ambiguas) — deben devolver null, nunca un período adivinado.
 */
function periodDetector(): TrainingPeriodDetector
{
    return new TrainingPeriodDetector;
}

it('detects "esta semana" as current_week', function () {
    expect(periodDetector()->detect('¿Cómo van mis entrenos de esta semana?'))->toBe('current_week');
});

it('detects "últimas 4 semanas" (con y sin tilde) as last_4_weeks', function (string $message) {
    expect(periodDetector()->detect($message))->toBe('last_4_weeks');
})->with([
    'con tilde' => ['¿Cuántas sesiones he completado en las últimas 4 semanas?'],
    'sin tilde' => ['Cuantas sesiones en las ultimas 4 semanas'],
]);

it('detects "en total" and "desde que empecé" as all_time', function (string $message) {
    expect(periodDetector()->detect($message))->toBe('all_time');
})->with([
    'en total' => ['¿Cuántas sesiones he completado en total?'],
    'desde que empecé, con tilde' => ['¿Cuántas llevo desde que empecé?'],
    'desde que empece, sin tilde' => ['Cuantas llevo desde que empece'],
]);

it('never treats "el último mes" as an automatic equivalent of last_4_weeks — ambiguous, excluded on purpose', function () {
    expect(periodDetector()->detect('¿Cómo voy en el último mes?'))->toBeNull();
});

it('never treats "en general" as an automatic equivalent of all_time — ambiguous, excluded on purpose', function () {
    expect(periodDetector()->detect('¿Cómo voy en general?'))->toBeNull();
});

it('returns null for a vague temporal phrase not in the closed vocabulary ("últimamente")', function () {
    expect(periodDetector()->detect('¿Cómo van mis entrenamientos últimamente?'))->toBeNull();
});

it('returns null for a message unrelated to progress/periods', function () {
    expect(periodDetector()->detect('Quiero entrenar'))->toBeNull();
});

it('returns null for an empty message, without throwing', function () {
    expect(periodDetector()->detect(''))->toBeNull();
    expect(periodDetector()->detect('   '))->toBeNull();
});

it('is case-insensitive', function () {
    expect(periodDetector()->detect('ESTA SEMANA cómo voy?'))->toBe('current_week');
});
