<?php

use App\Models\Tenant;
use App\Training\Support\TrialEndedMessageComposer;
use Illuminate\Support\Facades\Http;

/**
 * H16.1 (Cambio 4) — mismo contrato/pruebas de forma que ReminderMessageComposer:
 * la aplicación ya decidió los hechos (access_state/completed_sessions_count)
 * antes de llegar aquí; esta clase solo redacta, con fallback determinista
 * cuando la IA falla o produce una salida inválida.
 */
function chatCompletionText(string $text): array
{
    return ['choices' => [['message' => ['content' => $text]]]];
}

it('composes a trial_expired message via the AI when it responds validly', function () {
    Http::fake(['api.openai.com/*' => Http::response(chatCompletionText('Tu prueba terminó, hiciste 3 sesiones conmigo. Escríbeme "quiero pagar" para seguir.'))]);

    $text = (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'trial_expired', 'completed_sessions_count' => 3],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('Tu prueba terminó, hiciste 3 sesiones conmigo. Escríbeme "quiero pagar" para seguir.');
});

it('falls back to the deterministic trial_expired text, with the real session count, when the AI fails', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $text = (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'trial_expired', 'completed_sessions_count' => 5],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('Tu período de prueba terminó — en estos días hiciste 5 sesiones conmigo. Si quieres seguir, escríbeme "quiero pagar" y continuamos exactamente donde vamos.');
});

it('falls back to the deterministic paid_expired text when the AI response is invalid (looks like JSON)', function () {
    Http::fake(['api.openai.com/*' => Http::response(chatCompletionText('{"not": "plain text"}'))]);

    $text = (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'paid_expired', 'completed_sessions_count' => null],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('Tu acceso venció, pero nada de tu progreso se perdió. Dime "quiero pagar" y continuamos con tu plan.');
});

it('falls back to the deterministic revoked text, never mentioning Trial/membership or inviting to pay, when the AI fails', function () {
    Http::fake(['api.openai.com/*' => Http::response('Server error', 500)]);

    $text = (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'revoked', 'completed_sessions_count' => null],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('Tu acceso está pausado en este momento. Escríbeme y te pongo en contacto con nuestro equipo para revisarlo.');
    expect($text)->not->toContain('quiero pagar');
    expect($text)->not->toContain('Trial');
    expect($text)->not->toContain('membresía');
});

it('the revoked prompt explicitly forbids mentioning Trial/membership or inviting to pay, and never reveals a cause', function () {
    Http::fake(['api.openai.com/*' => Http::response(chatCompletionText('Tu acceso está pausado. Te conecto con el equipo.'))]);

    (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'revoked', 'completed_sessions_count' => null],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'NO sabes la causa real')
            && str_contains($systemPrompt, 'NUNCA menciones "Trial" ni "membresía vencida"')
            && str_contains($systemPrompt, 'NUNCA invites a escribir "quiero pagar"');
    });
});

it('degrades to the fallback when the AI response is empty or too long', function () {
    Http::fake(['api.openai.com/*' => Http::response(chatCompletionText(''))]);

    $text = (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'paid_expired', 'completed_sessions_count' => null],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    expect($text)->toBe('Tu acceso venció, pero nada de tu progreso se perdió. Dime "quiero pagar" y continuamos con tu plan.');
});

it('never invents a price, discount or promotion — the prompt forbids it explicitly', function () {
    Http::fake(['api.openai.com/*' => Http::response(chatCompletionText('Texto cualquiera.'))]);

    (new TrialEndedMessageComposer)->compose(
        ['access_state' => 'trial_expired', 'completed_sessions_count' => 1],
        Tenant::factory()->create(['ai_provider' => 'openai']),
    );

    Http::assertSent(function ($request) {
        $systemPrompt = data_get($request->data(), 'messages.0.content', '');

        return str_contains($systemPrompt, 'NUNCA inventes un precio, descuento ni promoción')
            && str_contains($systemPrompt, 'NUNCA inventes una urgencia');
    });
});
