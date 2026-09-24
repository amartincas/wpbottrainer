<?php

use App\Training\Enums\PreferenceMessageCategory;
use App\Training\Support\TrainingPreferenceMessageClassifier;

/**
 * Hito B3 (diseño v3 FINAL) — matriz completa de la gramática determinista
 * (Secciones 1/2/A.2, más los casos de la revisión crítica v3). Cobertura
 * exhaustiva de las ~24 frases usadas para cerrar el diseño — este archivo
 * ES el contrato funcional prometido en la Sección 5/B.
 */
function preferenceClassifier(): TrainingPreferenceMessageClassifier
{
    return new TrainingPreferenceMessageClassifier;
}

// ── Preference explícita (Dislike) ──

it('classifies explicit dislike statements as Dislike', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Dislike);
})->with([
    'No me gustan las sentadillas.',
    'No me gusta hacer sentadillas.',
    'Prefiero no hacer sentadillas.',
    'No soy fan de las sentadillas.',
    'Las sentadillas no son lo mío.',
    'No me gusta usar mancuernas.',
]);

it('extracts a candidate term from Dislike statements', function () {
    $result = preferenceClassifier()->classify('No me gustan las sentadillas.');
    expect($result->candidateTerm)->toBe('sentadillas');
});

// ── Permanencia ──

it('classifies permanence statements as Permanence', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Permanence);
})->with([
    'No quiero volver a hacer sentadillas.',
    'Ya no quiero hacer sentadillas.',
    'Nunca más sentadillas.',
    'De ahora en adelante no quiero sentadillas.',
]);

it('detects the permanence marker even without a "quiero hacer" verb phrase ("Nunca más sentadillas")', function () {
    $result = preferenceClassifier()->classify('Nunca más sentadillas.');
    expect($result->category)->toBe(PreferenceMessageCategory::Permanence);
    expect($result->candidateTerm)->toBe('sentadillas');
});

// ── Rechazo puntual (InstanceAnchor) — nunca genera Preference ──

it('classifies instance-anchored refusals as InstanceAnchor, never Dislike/Permanence', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::InstanceAnchor);
})->with([
    'No quiero hacer esta sentadilla.',
    'No quiero hacerla.',
    'Esta no la quiero hacer.',
]);

it('InstanceAnchor wins over Dislike when both markers co-occur ("Prefiero no hacer esta")', function () {
    $result = preferenceClassifier()->classify('Prefiero no hacer esta.');
    expect($result->category)->toBe(PreferenceMessageCategory::InstanceAnchor);
});

// ── Temporal — nunca persiste ──

it('classifies temporal-qualified refusals as Temporal', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Temporal);
})->with([
    'Hoy no quiero hacer sentadillas.',
    'Por ahora no quiero hacer sentadillas.',
    'Esta semana no quiero hacer sentadillas.',
    'Hoy no quiero usar mancuernas.',
]);

it('classifies a fatigue-causal refusal as Temporal even without an explicit temporal word', function () {
    $result = preferenceClassifier()->classify('No quiero hacer sentadillas porque estoy cansado.');
    expect($result->category)->toBe(PreferenceMessageCategory::Temporal);
});

it('never classifies a bare mention of "hoy" without a refusal as Temporal (no false positive)', function () {
    $result = preferenceClassifier()->classify('Hoy hice mi rutina completa.');
    expect($result->category)->toBeNull();
});

// ── Safety — máxima precedencia ──

it('classifies injury markers as Safety with subcategory injury', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Safety);
    expect($result->safetySubcategory)->toBe('injury');
})->with([
    'No puedo hacer sentadillas porque me duele la rodilla.',
    'No puedo hacer sentadillas porque me lastimé.',
    'No puedo hacer sentadillas por una lesión.',
    'No puedo hacer sentadillas porque tengo una lesión.',
]);

it('classifies surgery/recovery markers as Safety with subcategory recovery', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Safety);
    expect($result->safetySubcategory)->toBe('recovery');
})->with([
    'No puedo hacer sentadillas porque tuve una operación.',
    'No puedo usar mancuernas porque me operaron.',
]);

it('Safety wins over every other marker when it co-occurs', function () {
    // Contiene "no quiero volver a" (permanencia) Y un marcador de lesión —
    // Safety debe ganar siempre (precedencia máxima, Regla 11 del encargo).
    $result = preferenceClassifier()->classify('No quiero volver a hacer sentadillas, me lastimé.');
    expect($result->category)->toBe(PreferenceMessageCategory::Safety);
});

// ── "No puedo" — Ambiguous vs. Safety vs. Availability vs. Preference ──

it('classifies bare "no puedo" (no causal clause) as Ambiguous with noPuedoBare=true', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Ambiguous);
    expect($result->noPuedoBare)->toBeTrue();
})->with([
    'No puedo usar mancuernas.',
    'No puedo hacer sentadillas.',
]);

it('never classifies "no puedo" + availability clause ("no tengo") as any B3 category', function () {
    $result = preferenceClassifier()->classify('No puedo usar mancuernas porque no tengo.');
    expect($result->category)->toBeNull();
});

it('classifies "no puedo" + dislike clause as Dislike, not Ambiguous', function () {
    $result = preferenceClassifier()->classify('No puedo usar mancuernas, no me gustan.');
    expect($result->category)->toBe(PreferenceMessageCategory::Dislike);
});

// ── Ambiguo — ActionRefusal desnudo ──

it('classifies bare "no quiero hacer/usar X" as ActionRefusal (context-dependent, resolved by TrainingHandler)', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::ActionRefusal);
})->with([
    'No quiero hacer sentadillas.',
    'No quiero sentadillas.',
]);

// ── None — mensajes sin relación con el dominio ──

it('classifies unrelated messages as None (category null)', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBeNull();
})->with([
    '¿Cuántas series me tocan hoy?',
    'Hice 3 series de 10 con 40kg.',
    '',
]);

// ── Regresión explícita — vocabulario ampliado en v3 ──

it('regression: "no soy fan de" and "no es/son lo mío" are recognized (v3 grammar gap fix)', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Dislike);
})->with([
    'No soy fan de los burpees.',
    'Los burpees no son lo mío.',
]);

it('regression: "me operaron"/"cirugía" are recognized as Safety (v3 grammar gap fix)', function (string $body) {
    $result = preferenceClassifier()->classify($body);
    expect($result->category)->toBe(PreferenceMessageCategory::Safety);
})->with([
    'No puedo hacer sentadillas, me operaron.',
    'No puedo hacer sentadillas por una cirugía reciente.',
]);
