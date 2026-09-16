<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\Http;

/**
 * Corrección post-P1-A (auditoría 2026-09-16): antes de P1-A, "sesión
 * Scheduled con algo sin ExerciseLog" implicaba SIEMPRE "el usuario ya tiene
 * el ejercicio en su chat, solo falta que reporte" — la entrega progresiva de
 * H16.2 garantizaba que nunca hubiera un ejercicio pendiente sin entregar.
 * P1-A introdujo por primera vez ese estado intermedio real (`delivered_at`
 * null en un WorkoutExercise sin ExerciseLog), pero
 * TrainingHandler::executeTurnActions() (rama DeliverSession) no lo
 * distinguía: `continue_training` siempre respondía el texto genérico
 * PENDING_SESSION_REMINDER, incluso cuando el usuario pedía por primera vez
 * un ejercicio que nunca se le había entregado.
 *
 * Helpers con prefijo "pendingDelivery" — propios de este archivo, mismo
 * criterio que el resto de la suite de Training para evitar colisión de
 * funciones globales entre archivos de test (Pest ejecuta todos los
 * archivos en el mismo proceso PHP).
 */
function pendingDeliveryReadyContact(): Contact
{
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'health_screening_asked' => true]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

/**
 * @param  array<int, array{name: string, delivered_at: \Illuminate\Support\Carbon|null, logged: bool}>  $exercises
 * @return array{0: WorkoutSession, 1: array<int, WorkoutExercise>}
 */
function pendingDeliverySession(Contact $contact, array $exercises): array
{
    $session = WorkoutSession::factory()->create(['contact_id' => $contact->id, 'status' => WorkoutSessionStatus::Scheduled]);
    $created = [];

    foreach ($exercises as $i => $spec) {
        $exercise = Exercise::factory()->create(['name' => $spec['name']]);
        $workoutExercise = WorkoutExercise::factory()->create([
            'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id,
            'exercise_snapshot' => $exercise->toSnapshot(),
            'order' => $i + 1,
            'delivered_at' => $spec['delivered_at'],
        ]);

        if ($spec['logged']) {
            ExerciseLog::factory()->create(['workout_exercise_id' => $workoutExercise->id]);
        }

        $created[] = $workoutExercise;
    }

    return [$session, $created];
}

function pendingDeliverySendMessage(Contact $contact, string $body): void
{
    $tenant = $contact->tenant;
    $job = new ProcessWhatsAppMessage($tenant, $contact->customer_phone, $body, 'wamid.'.uniqid(), 'text', null);
    app()->call([$job, 'handle']);
}

/**
 * Forma mínima válida para ExecutionReportService::parseJson() — mismo
 * criterio que nudgeReportTurn() en NudgeUnreportedExercisesTest.php.
 */
function pendingDeliveryReportTurn(array $reports = [], array $intents = [], bool $sessionFinished = false): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'safety_signal_text' => null,
        'reports' => $reports,
        'session_finished' => $sessionFinished,
        'intents' => $intents,
        'training_reply' => null,
        'reminder_day' => null, 'reminder_time' => null, 'reminder_recurrence' => null, 'reminder_confirmation' => null,
    ])]]]];
}

function pendingDeliveryOutboundBodies(): \Illuminate\Support\Collection
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com'))
        ->map(fn ($pair) => data_get($pair[0]->data(), 'text.body', ''));
}

// ── 1: ejercicio pendiente nunca entregado ──

it('delivers the pending exercise when it was never delivered, instead of the generic reminder', function () {
    $contact = pendingDeliveryReadyContact();
    [$session, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Sentadilla búlgara', 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(intents: ['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, 'Dame el entrenamiento de hoy');

    $bodies = pendingDeliveryOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Sentadilla búlgara')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Ya tienes una sesión de entrenamiento pendiente')))->toBeFalse();
    expect($exercises[0]->fresh()->delivered_at)->not->toBeNull();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

// ── 2: variantes de redacción de la misma solicitud ──

it('delivers the pending exercise for the phrasing "Cuál entreno está pendiente?"', function () {
    $contact = pendingDeliveryReadyContact();
    [, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Remo con banda', 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(intents: ['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, 'Cuál entreno está pendiente?');

    $bodies = pendingDeliveryOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Remo con banda')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Ya tienes una sesión de entrenamiento pendiente')))->toBeFalse();
    expect($exercises[0]->fresh()->delivered_at)->not->toBeNull();
});

it('delivers the pending exercise for the phrasing "Dime el entreno que está pendiente para completarlo"', function () {
    $contact = pendingDeliveryReadyContact();
    [, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Zancadas con mancuerna', 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(intents: ['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, 'Dime el entreno que está pendiente para completarlo');

    $bodies = pendingDeliveryOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Zancadas con mancuerna')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Ya tienes una sesión de entrenamiento pendiente')))->toBeFalse();
    expect($exercises[0]->fresh()->delivered_at)->not->toBeNull();
});

// ── 3: regresión D052 — ya entregado, nunca se reenvía ──

it('D052 regression: never resends the card once the pending exercise was already delivered', function () {
    $contact = pendingDeliveryReadyContact();
    [$session, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Press militar', 'delivered_at' => now()->subMinutes(10), 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(intents: ['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, 'Dame el entrenamiento de hoy');

    $bodies = pendingDeliveryOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Ya tienes una sesión de entrenamiento pendiente')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Press militar')))->toBeFalse();
    expect(WorkoutSession::where('contact_id', $contact->id)->count())->toBe(1);
});

// ── 4: progresividad — entrega el primero sin ExerciseLog, no el 1 ni el 3 ──

it('delivers exercise 2 (first without an ExerciseLog), never exercise 1 (already logged) nor exercise 3', function () {
    $contact = pendingDeliveryReadyContact();
    [$session, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Peso muerto rumano', 'delivered_at' => now()->subHours(2), 'logged' => true],
        ['name' => 'Curl martillo', 'delivered_at' => null, 'logged' => false],
        ['name' => 'Elevaciones laterales', 'delivered_at' => null, 'logged' => false],
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(intents: ['continue_training'])),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, 'Dame el entrenamiento de hoy');

    $bodies = pendingDeliveryOutboundBodies();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Curl martillo')))->toBeTrue();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Peso muerto rumano')))->toBeFalse();
    expect($bodies->contains(fn ($b) => str_contains($b, 'Elevaciones laterales')))->toBeFalse();

    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull(); // Curl martillo: entregado ahora
    expect($exercises[2]->fresh()->delivered_at)->toBeNull(); // Elevaciones laterales: sigue sin entregar
});

// ── 5: reporte real + solicitud de entrenamiento en el mismo mensaje ──

it('a real report combined with continue_training still records the report and advances normally, without the new branch interfering', function () {
    $contact = pendingDeliveryReadyContact();
    [$session, $exercises] = pendingDeliverySession($contact, [
        ['name' => 'Hip thrust', 'delivered_at' => now()->subMinutes(30), 'logged' => false],
        ['name' => 'Prensa de piernas', 'delivered_at' => null, 'logged' => false],
    ]);
    // prescribed_sets=3 por defecto de la factory — el reporte de abajo
    // envía exactamente 3 series, para que ExecutionReportRecorder lo trate
    // como un reporte completo (avanza), no parcial.

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(pendingDeliveryReportTurn(
            reports: [['exercise_name' => null, 'not_performed' => false, 'skip_reason' => null, 'sets' => [
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
                ['reps' => 10, 'load' => 40, 'duration_seconds' => null],
            ], 'rpe_number' => 7, 'rpe_category' => null, 'note' => null, 'uncertain' => false]],
            intents: ['continue_training'],
        )),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    pendingDeliverySendMessage($contact, '3 series de 10 con 40kg, y dame mi entrenamiento');

    // El reporte se registró normalmente sobre el ejercicio ya entregado.
    expect($exercises[0]->fresh()->exerciseLog)->not->toBeNull();

    // El flujo progresivo existente (recordExecutionReport -> deliverExercise
    // del siguiente) ya se encargó de entregar el ejercicio 2 — la nueva
    // rama de DeliverSession no debe volver a entregarlo una segunda vez.
    $bodies = pendingDeliveryOutboundBodies();
    $secondExerciseCards = $bodies->filter(fn ($b) => str_contains($b, 'Prensa de piernas'));
    expect($secondExerciseCards)->toHaveCount(1);
    expect($exercises[1]->fresh()->delivered_at)->not->toBeNull();
});
