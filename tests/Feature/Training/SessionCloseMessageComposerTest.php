<?php

use App\Models\Tenant;
use App\Training\Enums\SessionCloseIntent;
use App\Training\Support\SessionCloseMessageComposer;
use Illuminate\Support\Facades\Http;

/**
 * H16.2 Fase 1 — mismo contrato de forma que TrialEndedMessageComposer/
 * ReminderMessageComposer: la aplicación ya decidió SessionCloseIntent y los
 * hechos (pendientes/registrado/omitido) antes de llegar aquí; esta clase
 * solo redacta, con validate()+fallback determinista cuando la IA falla,
 * produce una salida inválida, o CONTRADICE semánticamente la intención ya
 * decidida.
 */
function sessionCloseAiResponse(string $text): array
{
    return ['choices' => [['message' => ['content' => $text]]]];
}

it('composes a BlockedStillPending message via the AI when it responds validly and never claims completion', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('¡Casi, Alex! Todavía nos faltan sentadilla y fondos en banco. Cuando los tengas, cerramos.'))]);

    $text = (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::BlockedStillPending,
        ['intent' => 'blocked_still_pending', 'contact_name' => 'Alex', 'pending_exercises' => ['Sentadilla', 'Fondos en banco'], 'logged_summaries' => [], 'skipped_exercise_names' => []],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('¡Casi, Alex! Todavía nos faltan sentadilla y fondos en banco. Cuando los tengas, cerramos.');
});

it('degrades BlockedStillPending to the deterministic fallback, naming the real pending exercises, when the AI fails', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $text = (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::BlockedStillPending,
        ['intent' => 'blocked_still_pending', 'contact_name' => null, 'pending_exercises' => ['Sentadilla', 'Fondos en banco'], 'logged_summaries' => [], 'skipped_exercise_names' => []],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('¡Casi! 💪 Todavía te faltan estos ejercicios: Sentadilla, Fondos en banco. Cuando los tengas, cuéntame y cerramos el entrenamiento.');
});

it('rejects a BlockedStillPending response that falsely claims the session is completed, and uses the fallback instead', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('🏁 Sesión completada. ¡Buen trabajo!'))]);

    $text = (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::BlockedStillPending,
        ['intent' => 'blocked_still_pending', 'contact_name' => null, 'pending_exercises' => ['Sentadilla'], 'logged_summaries' => [], 'skipped_exercise_names' => []],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('¡Casi! 💪 Todavía te faltan estos ejercicios: Sentadilla. Cuando los tengas, cuéntame y cerramos el entrenamiento.');
    expect($text)->not->toContain('completada');
});

it('rejects a SuccessFull response that falsely claims pending exercises remain, and uses the fallback instead', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('Todavía tienes pendientes: sentadilla.'))]);

    $text = (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::SuccessFull,
        ['intent' => 'success_full', 'contact_name' => null, 'pending_exercises' => [], 'logged_summaries' => ['Sentadilla: 10rep@40kg'], 'skipped_exercise_names' => []],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('🏁 ¡Entrenamiento completado! Buen trabajo. Escríbeme cuando quieras tu próximo entrenamiento.');
    expect($text)->not->toContain('pendientes');
});

it('composes a SuccessPartial fallback naming the real skipped exercises when the AI response is empty', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse(''))]);

    $text = (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::SuccessPartial,
        ['intent' => 'success_partial', 'contact_name' => null, 'pending_exercises' => [], 'logged_summaries' => ['Sentadilla: 10rep@40kg'], 'skipped_exercise_names' => ['Fondos en banco']],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('🏁 Cerré tu entrenamiento de hoy. Quedó pendiente: Fondos en banco — lo retomamos otro día. Escríbeme cuando quieras el siguiente.');
});

it('never invents an exercise, set or load not present in the facts — the prompt forbids it explicitly', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('Texto cualquiera.'))]);

    (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::BlockedStillPending,
        ['intent' => 'blocked_still_pending', 'contact_name' => null, 'pending_exercises' => ['Sentadilla'], 'logged_summaries' => [], 'skipped_exercise_names' => []],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'NUNCA inventes un ejercicio, una serie, una repetición, una carga')
            && str_contains($systemPrompt, 'NUNCA decidas tú si la sesión está completa');
    });
});

it('exposes "next_exercise" (the first pending) in the prompt only for BlockedStillPending, never for the other two intents', function () {
    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('Texto cualquiera.'))]);
    $tenant = Tenant::factory()->create(['ai_provider' => 'openai']);

    (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::BlockedStillPending,
        ['intent' => 'blocked_still_pending', 'contact_name' => null, 'pending_exercises' => ['Sentadilla', 'Fondos en banco'], 'next_exercise' => 'Sentadilla', 'logged_summaries' => [], 'skipped_exercise_names' => []],
        $tenant,
    );

    Http::assertSent(fn ($request) => str_contains(data_get($request->data(), 'messages.0.content', ''), 'next_exercise: Sentadilla'));

    Http::fake(['api.openai.com/*' => Http::response(sessionCloseAiResponse('Texto cualquiera.'))]);

    (new SessionCloseMessageComposer)->compose(
        SessionCloseIntent::SuccessFull,
        ['intent' => 'success_full', 'contact_name' => null, 'pending_exercises' => [], 'next_exercise' => null, 'logged_summaries' => ['3 series de 10 repeticiones con 8kg en Sentadilla'], 'skipped_exercise_names' => []],
        $tenant,
    );

    Http::assertNotSent(fn ($request) => str_contains(data_get($request->data(), 'messages.0.content', ''), 'next_exercise'));
});
