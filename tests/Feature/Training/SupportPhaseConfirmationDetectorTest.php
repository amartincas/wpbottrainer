<?php

use App\Training\Support\SupportPhaseConfirmationDetector;

/**
 * Hito de confirmación en lenguaje natural para Preparation/Cooldown
 * (diseño v4, aprobado tras auditoría de E2E real en staging) — cobertura
 * dedicada de `SupportPhaseConfirmationDetector::isExplicitConfirmation()`,
 * incluidas las 3 vías (exacta/prefijo/co-ocurrencia) y las dos nuevas
 * reglas de abstención (referencia a otro ejercicio, objeto no relacionado
 * con la ejecución). El vocabulario histórico (24 frases) ya está cubierto
 * por WorkoutExercisePhaseTest.php — este archivo cubre exclusivamente lo
 * nuevo del diseño v4.
 */
function detector(): SupportPhaseConfirmationDetector
{
    return new SupportPhaseConfirmationDetector;
}

// ── Positivos — sin necesitar $exerciseName (vías A/B) ──

it('confirms via prefix/exact match without needing the exercise name', function (string $body) {
    expect(detector()->isExplicitConfirmation($body))->toBeTrue();
})->with([
    'Listo, una serie x 90 segundos',
    'Lista, una serie x 90 segundos',
    'Hice una serie x 90 segundos',
    'Ya hice Rodillas altas',
    'Hice una serie x 90 segundos de Rodillas altas',
    'Terminé Rodillas altas',
    'Listo, serie de 90 segundos',
    'Hice otra serie x 90 segundos',
    'Lista, hice otra serie de 90 segundos',
    'Terminada las 3 series x 10 repeticiones @ 8kg',
    'Hecha la serie',
    'Terminado el ejercicio',
]);

// ── Positivos — requieren $exerciseName (vía C, confirmación no está al inicio) ──

it('confirms via co-occurrence (Vía C) when the confirmation phrase is not at the start but the current Support name is present', function (string $body) {
    expect(detector()->isExplicitConfirmation($body, 'Rodillas altas'))->toBeTrue();
})->with([
    'Rodillas altas, hice una serie x 90 segundos',
    'Rodillas altas, ya terminé',
    'Rodillas altas listo',
    'Rodillas altas, hice otra serie x 90 segundos',
]);

// ── Negativos — protecciones obligatorias ──

it('never confirms — mention alone, questions, negation, pain', function (string $body) {
    expect(detector()->isExplicitConfirmation($body, 'Rodillas altas'))->toBeFalse();
})->with([
    'Rodillas altas',
    '¿Rodillas altas?',
    '¿Cómo hago Rodillas altas?',
    'Rodillas altas me duele',
    'No pude hacer Rodillas altas',
    'No quiero hacer Rodillas altas',
    'No hice Rodillas altas',
]);

// ── Negativos — referencia explícita a OTRO ejercicio ──

it('never confirms when the message explicitly references a DIFFERENT exercise', function (string $body) {
    expect(detector()->isExplicitConfirmation($body, 'Rodillas altas'))->toBeFalse();
})->with([
    'Terminé el ejercicio anterior',
    'Hice el ejercicio anterior',
    'Ya hice el otro ejercicio',
    'Terminé el anterior',
]);

// ── Negativos — "hice"/"hecho" gobernando un objeto no relacionado con la ejecución ──

it('never confirms when hice/hecho directly governs a non-completion object (pregunta/video/foto)', function (string $body) {
    expect(detector()->isExplicitConfirmation($body, 'Rodillas altas'))->toBeFalse();
})->with([
    'Hice una pregunta sobre Rodillas altas',
    'Hice el video de Rodillas altas',
    'Hice una foto de Rodillas altas',
]);

// ── Casos importantes de la revisión crítica v4 ──

it('does NOT confirm "Vi el video, listo" — "listo" is not at the start and no exercise name co-occurs meaningfully with it as a completion', function () {
    expect(detector()->isExplicitConfirmation('Vi el video, listo', 'Rodillas altas'))->toBeFalse();
});

it('does NOT confirm "Vi el video y terminé" — same reason, "terminé" is not at the start', function () {
    expect(detector()->isExplicitConfirmation('Vi el video y terminé', 'Rodillas altas'))->toBeFalse();
});

it('CONFIRMS "Hice el ejercicio, vi el video y terminé" — "hice" governs "el ejercicio", not "el video" (adjacency, not mere presence)', function () {
    expect(detector()->isExplicitConfirmation('Hice el ejercicio, vi el video y terminé', 'Rodillas altas'))->toBeTrue();
});

it('documents the v4 behavior change: "Ya hice el otro" (without the word "ejercicio") now CONFIRMS — "otro" alone is no longer a blanket rejector, only "otro ejercicio" is', function () {
    expect(detector()->isExplicitConfirmation('Ya hice el otro', 'Rodillas altas'))->toBeTrue();
});

// ── Vía C — el nombre se compara contra el Support ACTUAL, nunca cualquiera ──

it('Vía C confirms when the correct current Support name is provided and mentioned', function () {
    expect(detector()->isExplicitConfirmation('Rodillas altas, ya terminé', 'Rodillas altas'))->toBeTrue();
});

it('Vía C does NOT confirm when the exercise name provided differs from the one mentioned in the message', function () {
    // El mensaje menciona "Rodillas altas", pero el Support actual real es
    // "Zancadas" — nunca debe confirmar comparando contra un nombre que no
    // es el que el llamador resolvió como frente real.
    expect(detector()->isExplicitConfirmation('Rodillas altas, ya terminé', 'Zancadas'))->toBeFalse();
});

// ── Regresión explícita: "hice otra serie" nunca se confunde con "otro ejercicio" ──

it('regression: "otra"/"otro" qualifying "serie" (not "ejercicio") never triggers the other-exercise rejection', function (string $body) {
    expect(detector()->isExplicitConfirmation($body))->toBeTrue();
})->with([
    'Hice otra serie x 90 segundos',
    'Lista, hice otra serie de 90 segundos',
]);
