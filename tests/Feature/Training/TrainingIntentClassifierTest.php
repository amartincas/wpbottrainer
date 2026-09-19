<?php

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\IngestedMessage;
use App\Core\Messaging\Intent;
use App\Models\Tenant;
use App\Training\Support\TrainingIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md): TrainingIntentClassifier
 * es EXCLUSIVAMENTE explícito (solo keywords) desde la corrección de
 * precedencia — su parte contextual (onboarding incompleto, WorkoutSession
 * pendiente, acceso activo esperando primer entrenamiento, Reminder
 * esperando respuesta) se extrajo a TrainingContextualIntentClassifier, ver
 * TrainingContextualIntentClassifierTest.php.
 */
function makeClassifierContext(Tenant $tenant, ?string $body, string $from = '573001112233'): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, $body, 'wamid.1', 'text', null),
    );
}

it('classifies a message with training keywords as Intent::Training', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Quiero empezar a entrenar')))->toBe(Intent::Training);
    expect($classifier->classify(makeClassifierContext($tenant, 'dame una rutina de gimnasio')))->toBe(Intent::Training);
});

it('declines (returns null) for unrelated messages with no training signal', function () {
    $tenant = Tenant::factory()->create();
    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, 'Hola, ¿cómo estás?')))->toBeNull();
    expect($classifier->classify(makeClassifierContext($tenant, null)))->toBeNull();
});

it('never consults Contact/state — declines for a contact mid-onboarding without a keyword (moved to TrainingContextualIntentClassifier)', function () {
    // Regresión explícita de la extracción: antes de la corrección de
    // precedencia, este mismo caso clasificaba como Training AQUÍ. Ahora
    // TrainingIntentClassifier ya no consulta Contact en absoluto — ver
    // TrainingContextualIntentClassifierTest.php para el comportamiento
    // contextual real (que se preserva sin cambios, solo en otra clase).
    $tenant = Tenant::factory()->create();
    $contact = \App\Models\Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    \App\Models\TrainingProfile::factory()->incomplete()->create(['contact_id' => $contact->id]);

    $classifier = new TrainingIntentClassifier;

    expect($classifier->classify(makeClassifierContext($tenant, '3 veces por semana', '573001112233')))->toBeNull();
});
