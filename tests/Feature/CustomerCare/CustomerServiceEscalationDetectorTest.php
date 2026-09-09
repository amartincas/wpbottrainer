<?php

use App\CustomerCare\Support\CustomerServiceEscalationDetector;

it('recognizes every explicit example phrase from the design', function (string $message) {
    expect((new CustomerServiceEscalationDetector)->detect($message))->toBeTrue();
})->with([
    'Quiero hablar con alguien',
    'Necesito atención',
    'Tengo un problema con el pago',
    'No puedo continuar',
    'Necesito ayuda',
    'El video no carga y necesito ayuda',
]);

it('does not match unrelated conversational messages', function (string $message) {
    expect((new CustomerServiceEscalationDetector)->detect($message))->toBeFalse();
})->with([
    '¿Cuánto cuesta la membresía?',
    'Hice 10 con 40',
    'Listo, terminé la primera serie',
    'Quiero entrenar',
]);

it('never matches a bare "pago" alone — must not collide with PaymentIntentClassifier keywords', function () {
    expect((new CustomerServiceEscalationDetector)->detect('¿Cómo pago mi membresía?'))->toBeFalse();
});
