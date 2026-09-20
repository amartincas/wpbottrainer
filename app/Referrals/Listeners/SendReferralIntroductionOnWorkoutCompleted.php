<?php

namespace App\Referrals\Listeners;

use App\Core\Notifications\CustomerNotifier;
use App\Models\Contact;
use App\Models\WorkoutSession;
use App\Training\Enums\WorkoutSessionStatus;
use App\Training\Events\WorkoutSessionCompleted;
use Illuminate\Support\Facades\Log;

/**
 * Referral Introduction (ver docs/DECISIONS.md) — segundo consumidor real
 * de un evento de dominio ajeno a Referrals, mismo patrón exacto que
 * `ApplyReferralRewardOnPaymentConfirmed` con `PaymentConfirmed`: reacciona
 * al HECHO ya ocurrido (`WorkoutSessionCompleted`), nunca escribe en
 * `WorkoutSession`/`ExecutionReportRecorder`/`TrainingEngine`, y vive en
 * `App\Referrals` — nunca al revés (`App\Training` no conoce este listener
 * ni ningún concepto de Referrals, verificado por `ReferralIsolationArchTest`).
 *
 * `referral_introduction_sent_at` vive en `Contact` (no en
 * `TrainingProfile`) — es estado de una comunicación/proactividad del
 * PROGRAMA de Referral, no del perfil de entrenamiento. Consecuencia
 * directa: este listener no necesita conocer `TrainingProfile` en
 * absoluto — solo `Contact` y `WorkoutSession`.
 *
 * "Primer entrenamiento completado" — condición inequívoca, sin ambigüedad:
 * exactamente 1 `WorkoutSession` con `status=Completed` existe para este
 * Contact en el momento de procesar el evento (la que acaba de completarse,
 * y ninguna otra antes). Un `Skipped` nunca cuenta como "entrenamiento
 * completado" para este propósito.
 *
 * `referral_program_enabled=false` bloquea TODO — ni se envía, ni se
 * reclama el marker (el chequeo corre antes que `claimIntroduction()`) —
 * así, si el programa se habilita más adelante, el Contact sigue siendo
 * elegible para recibir la introducción en su próximo entrenamiento... salvo
 * que ya haya pasado su ÚNICO primer entrenamiento mientras estaba
 * deshabilitado, en cuyo caso nunca más habrá un "primer" que dispare esto
 * — limitación aceptada, ver reporte de decisiones pendientes.
 *
 * Idempotencia — dos capas independientes, mismo criterio ya usado en el
 * resto del proyecto para "como máximo una vez":
 *  1. Claim atómico persistido: `Contact.referral_introduction_sent_at`
 *     (nullable, write-once) — un `UPDATE ... WHERE referral_introduction_sent_at
 *     IS NULL` cuyo conteo de filas afectadas decide si este proceso ganó
 *     el derecho a enviar; nunca una variable de memoria/caché/Redis
 *     temporal. Si el mismo evento se procesa dos veces (o dos ejecuciones
 *     concurrentes), la segunda ve 0 filas afectadas y no hace nada.
 *  2. `CustomerNotifier` con `idempotencyKey` determinista
 *     (`"referral_introduction:{contact_id}"`) — la misma garantía de "como
 *     máximo una entrega CONFIRMADA" que ya usa el reward de Referral
 *     (Hito 10, cero código nuevo de idempotencia de notificación).
 *
 * Riesgo aceptado, ya existente e idéntico en el resto del proyecto (ver
 * docs/DECISIONS.md — análisis de claim-before-delivery): el claim ocurre
 * ANTES de `notify()`. Si `notify()` falla (Meta/red), el marker queda
 * puesto pero el mensaje nunca llegó — el mismo trade-off ya aceptado por
 * `ApplyReferralRewardOnPaymentConfirmed` y por el patrón de proactividad
 * de Hito P1-A, para evitar el riesgo opuesto (doble envío bajo
 * concurrencia). No se introduce aquí ningún mecanismo de outbox/reintento
 * — está deliberadamente fuera de alcance.
 *
 * NO crea `ReferralCode`/`Referral`/`ReferralReward` — el Contact solo
 * recibe el código, perezosamente, cuando él mismo lo pida (mismo mecanismo
 * ya existente de `ReferralHandler::handleGetInvitation()`, sin cambios).
 * NO modifica `TrainingAccess` — es puramente informativo.
 */
class SendReferralIntroductionOnWorkoutCompleted
{
    public function __construct(
        private readonly CustomerNotifier $notifier,
    ) {}

    public function handle(WorkoutSessionCompleted $event): void
    {
        $session = $event->session;
        $contact = $session->contact;

        if ($contact === null) {
            return;
        }

        $tenant = $contact->tenant;

        if (! $tenant->referral_program_enabled) {
            // Mismo gate que ReferralHandler ya respeta para cualquier
            // interacción de Referrals — introducir un programa desactivado
            // no tendría sentido. Corre ANTES del claim: un programa
            // desactivado nunca debe consumir el único "primer
            // entrenamiento" de un Contact.
            return;
        }

        if (! $this->isFirstCompletedWorkout($contact)) {
            return;
        }

        if (! $this->claimIntroduction($contact)) {
            // Ya reclamado por este mismo proceso u otro concurrente —
            // no-op silencioso, nunca un segundo envío.
            return;
        }

        $rewardDays = $tenant->referral_reward_days;
        $dayWord = $rewardDays === 1 ? 'día' : 'días';

        $message = "🎉 ¡Completaste tu primer entrenamiento!\n\n"
            .'Si conoces a alguien que también quiera tener un entrenador de ejercicios personalizado por WhatsApp, puedes invitarlo.'."\n\n"
            ."Si tu amigo se suscribe, ganas {$rewardDays} {$dayWord} adicionales de entrenamiento. 💪\n\n"
            .'Escríbeme "quiero invitar a un amigo" y te preparo la invitación.';

        $this->notifier->notify(
            tenant: $tenant,
            to: $contact->customer_phone,
            eventKey: 'referral_introduction',
            variables: ['reward_days' => (string) $rewardDays],
            freeFormText: $message,
            idempotencyKey: "referral_introduction:{$contact->id}",
        );

        Log::info('REFERRAL_INTRODUCTION_SENT', [
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'workout_session_id' => $session->id,
        ]);
    }

    private function isFirstCompletedWorkout(Contact $contact): bool
    {
        return WorkoutSession::where('contact_id', $contact->id)
            ->where('status', WorkoutSessionStatus::Completed)
            ->count() === 1;
    }

    /**
     * Claim atómico: solo un proceso puede transicionar
     * `referral_introduction_sent_at` de `null` a un valor real. Devuelve
     * `true` únicamente para quien realmente ganó la carrera.
     */
    private function claimIntroduction(Contact $contact): bool
    {
        $claimed = Contact::where('id', $contact->id)
            ->whereNull('referral_introduction_sent_at')
            ->update(['referral_introduction_sent_at' => now()]);

        return $claimed === 1;
    }
}
