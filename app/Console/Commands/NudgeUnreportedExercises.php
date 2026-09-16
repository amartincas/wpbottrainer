<?php

namespace App\Console\Commands;

use App\Core\Notifications\CustomerNotifier;
use App\Models\WorkoutExercise;
use App\Training\Support\ProactivityGate;
use App\Training\Support\UnreportedExerciseDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P1-A — programado cada minuto (ver routes/console.php). Delgado a
 * propósito: `UnreportedExerciseDetector` decide QUIÉN es candidato,
 * `ProactivityGate` decide si se puede enviar AHORA, `CustomerNotifier`
 * decide CÓMO entregarlo (mensaje libre vs. plantilla) — este comando solo
 * orquesta, sin lógica de negocio propia. Mismo patrón que
 * `reminders:dispatch-due`.
 *
 * Concurrencia (dos capas, ninguna nueva): `Schedule::command()->
 * withoutOverlapping()` evita que dos ejecuciones PROGRAMADAS de este mismo
 * comando corran a la vez (igual que reminders:dispatch-due/recover-stuck).
 * El `Cache::add()` de abajo (mismo primitivo atómico ya usado para la
 * idempotencia de WAMID en WhatsAppController) es una segunda barrera para
 * una ejecución manual concurrente fuera del scheduler — sin ella, dos
 * procesos podrían pasar ambos el chequeo inicial de CustomerNotifier antes
 * de que cualquiera confirme el envío (ver docblock de CustomerNotifier —
 * limitación estructural preexistente, no se modifica aquí).
 */
class NudgeUnreportedExercises extends Command
{
    protected $signature = 'training:nudge-unreported-exercises';

    protected $description = 'Sends a one-time WhatsApp nudge for each currently pending WorkoutExercise that has gone unreported past its tenant-configured threshold.';

    private const EVENT_KEY = 'exercise_nudge';

    private const CLAIM_TTL_MINUTES = 5;

    public function handle(UnreportedExerciseDetector $detector, ProactivityGate $gate, CustomerNotifier $notifier): int
    {
        $candidates = $detector->findCandidates();
        $sent = 0;

        foreach ($candidates as $workoutExercise) {
            $contact = $workoutExercise->workoutSession->contact;
            $tenant = $contact->tenant;

            if (! $gate->canSend($contact, self::EVENT_KEY)) {
                Log::info('EXERCISE_NUDGE_GATE_BLOCKED', ['workout_exercise_id' => $workoutExercise->id]);

                continue;
            }

            $claimKey = "exercise_nudge_claim:{$workoutExercise->id}";

            if (! Cache::add($claimKey, true, now()->addMinutes(self::CLAIM_TTL_MINUTES))) {
                // Otro proceso ya está atendiendo (o acaba de atender) este
                // mismo candidato en los últimos minutos — nunca se procesa
                // dos veces en la misma ventana.
                Log::info('EXERCISE_NUDGE_CLAIM_SKIPPED', ['workout_exercise_id' => $workoutExercise->id]);

                continue;
            }

            $result = $notifier->notify(
                $tenant,
                $contact->customer_phone,
                self::EVENT_KEY,
                [],
                $this->messageFor($workoutExercise),
                "exercise_nudge:{$workoutExercise->id}",
            );

            if ($result->confirmed) {
                $sent++;
            }
        }

        $this->info("Nudged {$sent} unreported exercise(s) out of {$candidates->count()} candidate(s).");

        return self::SUCCESS;
    }

    /**
     * Mensaje fijo determinista — decisión explícita del MVP (P1-A): esto es
     * un recordatorio operacional, no una redacción que se beneficie de IA,
     * y evita gastar una llamada de IA en un evento puramente proactivo.
     */
    private function messageFor(WorkoutExercise $workoutExercise): string
    {
        $exerciseName = $workoutExercise->exercise_snapshot['name'] ?? 'tu ejercicio pendiente';

        return "¿Sigues ahí? 👀 Todavía tengo pendiente tu reporte de {$exerciseName}. Cuéntame cómo te fue cuando puedas.";
    }
}
