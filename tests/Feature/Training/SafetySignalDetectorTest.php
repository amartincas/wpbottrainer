<?php

use App\Training\Support\SafetySignalDetector;

it('detects known red-flag phrases regardless of case', function () {
    $detector = new SafetySignalDetector;

    expect($detector->detect('Tengo un fuerte DOLOR DE PECHO ahora mismo'))->toBe('chest_pain');
    expect($detector->detect('me operaron la semana pasada'))->toBe('recent_surgery');
    expect($detector->detect('siento hormigueo severo en el brazo'))->toBe('neurological');
});

it('does not flag ordinary training limitations as safety alarms', function () {
    $detector = new SafetySignalDetector;

    expect($detector->detect('me duele un poco la rodilla al hacer sentadillas'))->toBeNull();
    expect($detector->detect('tengo el hombro sensible, prefiero evitar press militar'))->toBeNull();
});

/**
 * Hito 7 (hallazgo de la prueba E2E real): "me duele mucho el pecho" — una
 * paráfrasis obvia de "dolor de pecho" — no coincidía con ninguna frase
 * exacta de PATTERNS y pasaba desapercibida. Estas variaciones deben
 * detectarse como chest_pain vía la regla de co-ocurrencia
 * (trigger + anchor), sin depender de la frase literal exacta.
 */
it('detects chest_pain across reasonable phrasing variations, not just the exact literal phrase', function () {
    $detector = new SafetySignalDetector;

    expect($detector->detect('Me duele mucho el pecho, no puedo seguir'))->toBe('chest_pain');
    expect($detector->detect('me duele el pecho'))->toBe('chest_pain');
    expect($detector->detect('siento presión en el pecho'))->toBe('chest_pain');
    expect($detector->detect('tengo una molestia en el pecho'))->toBe('chest_pain');
    expect($detector->detect('siento una punzada en el pecho'))->toBe('chest_pain');
});

it('does not over-trigger chest_pain when only one half of the signal is present', function () {
    $detector = new SafetySignalDetector;

    // "duele"/"dolor" sin "pecho": otro tipo de molestia, no debe marcar chest_pain.
    expect($detector->detect('me duele un poco la rodilla al hacer sentadillas'))->toBeNull();
    // "pecho" sin ninguna palabra de dolor/molestia: mención neutra (ej. ejercicio de pecho).
    expect($detector->detect('hoy toca entrenar pecho y tríceps'))->toBeNull();
});

it('exposes a non-empty escalation message', function () {
    expect(SafetySignalDetector::ESCALATION_MESSAGE)->toBeString()->not->toBeEmpty();
});
