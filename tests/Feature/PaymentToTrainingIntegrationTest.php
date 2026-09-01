<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Exercise;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingAccess;
use App\Models\TrainingProfile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentConfirmationService;
use Illuminate\Support\Facades\Http;

/**
 * Prueba de integración de punta a punta (Hito 8.1) — reconstruye, de forma
 * automatizada, el hallazgo real del primer E2E comercial: tras confirmar un
 * Payment, el cliente recibía la invitación a entrenar pero su respuesta
 * ("Sí"/"Dale") se perdía en fallback_chat en vez de continuar por Training.
 *
 * Empieza con el onboarding YA completo a propósito — el flujo de onboarding
 * en sí (Extract+Narrate turno a turno) ya está cubierto exhaustivamente en
 * OnboardingConversationServiceTest.php/TrainingConversationFlowTest.php; lo
 * que esta prueba verifica es específicamente la continuidad Payment→Training
 * que causó el defecto real.
 */
function dispatchMessage(Tenant $tenant, string $from, ?string $body, string $type = 'text', ?string $mediaId = null): void
{
    $job = new ProcessWhatsAppMessage($tenant, $from, $body, 'wamid.'.uniqid(), $type, $mediaId);
    app()->call([$job, 'handle']);
}

it('continues into Training after a payment is confirmed and the user replies affirmatively to the training invite', function () {
    $tenant = Tenant::factory()->create([
        'ai_provider' => 'openai',
        'monthly_price' => 50000,
        'nequi_number' => '300-000-0001',
    ]);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233']);
    TrainingProfile::factory()->create(['contact_id' => $contact->id, 'restrictions' => [], 'available_equipment' => []]);
    User::factory()->create(['is_super_admin' => true, 'phone' => '573009990000']);
    // Ventana de WhatsApp abierta (CustomerNotifier) — en producción la
    // actualiza WhatsAppController::handle() en cada mensaje entrante; estas
    // pruebas despachan el Job directamente, sin pasar por el Controller.
    Conversation::create(['tenant_id' => $tenant->id, 'customer_phone' => '573001112233', 'last_session_at' => now()]);

    $exercise = Exercise::factory()->create(['muscle_group' => 'chest', 'name' => 'Flexiones', 'video_url' => 'https://videos.example.test/pushup.mp4']);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'amount' => 50000, 'date' => '2026-08-20', 'time' => null,
            'reference' => '123456789', 'entity' => 'Nequi', 'payer_name' => null, 'uncertain' => false,
        ])]]]], 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
    ]);

    // 1. Quiero pagar -> Nequi -> comprobante.
    dispatchMessage($tenant, '573001112233', 'Quiero pagar');
    dispatchMessage($tenant, '573001112233', 'Nequi');
    dispatchMessage($tenant, '573001112233', 'Le mandé 50 mil por Nequi, referencia 123456789, el 20 de agosto');

    $payment = Payment::where('contact_id', $contact->id)->first();
    expect($payment->status)->toBe(PaymentStatus::UnderReview);

    // 2. El superadmin confirma (equivalente a la acción de Filament).
    app(PaymentConfirmationService::class)->confirm($payment, User::where('is_super_admin', true)->first());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect(TrainingAccess::where('contact_id', $contact->id)->first()?->status->value)->toBe('active');

    // payment_confirmed + training_invite ya se enviaron (CustomerNotifier) y
    // quedaron persistidos en WhatsAppMessage (Hito 8.1).
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), 'confirmado'));
    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'text.body', ''), '¿Quieres que te prepare tu entrenamiento?'));

    // 3. El usuario responde afirmativamente, sin ninguna palabra clave de
    // Training — este es exactamente el mensaje que antes se perdía en
    // fallback_chat.
    dispatchMessage($tenant, '573001112233', 'Sí');

    // 4. Debe haber continuado por el flujo normal de Training: WorkoutSession
    // generada y video enviado — nunca otra pregunta de onboarding, nunca el
    // chat genérico de ecommerce.
    $session = WorkoutSession::where('contact_id', $contact->id)->first();
    expect($session)->not->toBeNull();
    expect($session->workoutExercises)->toHaveCount(1);

    Http::assertSent(fn ($request) => data_get($request->data(), 'type') === 'video'
        && data_get($request->data(), 'video.link') === $exercise->video_url);
});
