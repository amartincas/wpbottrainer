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

it('exposes a non-empty escalation message', function () {
    expect(SafetySignalDetector::ESCALATION_MESSAGE)->toBeString()->not->toBeEmpty();
});
