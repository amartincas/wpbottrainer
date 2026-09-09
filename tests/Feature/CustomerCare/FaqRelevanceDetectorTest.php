<?php

use App\CustomerCare\Support\FaqRelevanceDetector;

it('recognizes messages with an explicit question mark', function (string $message) {
    expect((new FaqRelevanceDetector)->looksLikeFaqQuestion($message))->toBeTrue();
})->with([
    '¿Cuánto cuesta la membresía?',
    '¿A qué hora abren?',
    'y el precio cuál es?',
]);

it('recognizes messages with an interrogative word anywhere, not only at the start', function (string $message) {
    expect((new FaqRelevanceDetector)->looksLikeFaqQuestion($message))->toBeTrue();
})->with([
    'quiero saber cuánto cuesta',
    'la verdad no sé cuánto cuesta esto',
    'dime dónde queda la sede',
]);

it('recognizes topic keywords without an explicit question form', function (string $message) {
    expect((new FaqRelevanceDetector)->looksLikeFaqQuestion($message))->toBeTrue();
})->with([
    'necesito el horario',
    'información sobre precios',
    'tienen descuentos',
]);

it('does not match unrelated training/report messages', function (string $message) {
    expect((new FaqRelevanceDetector)->looksLikeFaqQuestion($message))->toBeFalse();
})->with([
    'Hice 10 con 40',
    'Listo, terminé',
    'Sentadilla 3x10',
    '',
]);
