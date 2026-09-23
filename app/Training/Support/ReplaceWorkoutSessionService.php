<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\WorkoutSession;
use App\Training\Engine\TrainingEngine;
use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Hito B2 (Nueva rutina durante sesión activa) — orquesta el reemplazo de la
 * `WorkoutSession` `Scheduled` de un `Contact` por una nueva, a petición
 * explícita del usuario ("quiero otra rutina"). Capa de orquestación PURA:
 * NUNCA selecciona ejercicios, NUNCA duplica elegibilidad/foco/variedad/
 * progresión/seguridad — toda decisión de prescripción sigue siendo
 * exclusiva de `TrainingEngine::decideNextSession()`, invocado aquí sin
 * ningún parámetro nuevo (`decideNextSession()` no cambia de firma por este
 * hito).
 *
 * El mecanismo que hace esto posible ya existe en `TrainingEngine`, sin
 * modificarlo: `decideNextSession()` es idempotente — si encuentra una
 * `WorkoutSession` `Scheduled`, la devuelve sin crear ninguna nueva (ver
 * docblock de ese método). Este servicio simplemente cierra la sesión vieja
 * (`Scheduled -> Superseded`) ANTES de invocarlo, para que esa idempotencia
 * deje de aplicar y `TrainingEngine` cree una sesión genuinamente nueva —
 * exactamente el mismo mecanismo que ya usa cualquier otro llamador, sin
 * ninguna rama especial dentro de `TrainingEngine` para este caso.
 *
 * Dos entradas públicas, con responsabilidad DELIBERADAMENTE separada
 * (revisión final B2.3, punto 2):
 * - `replace()`: responsabilidad ÚNICA y estrecha — exactamente 1
 *   `Scheduled` -> la reemplaza; 0 -> `null` (NUNCA simula un reemplazo);
 *   >1 -> excepción. Nunca crea una sesión quando no había nada que
 *   reemplazar.
 * - `replaceOrCreate()`: el punto de entrada real que necesita B2 end-to-end
 *   ("dame otra rutina" con o sin sesión previa) — internamente decide lo
 *   mismo que `replace()`, pero cuando encuentra 0 `Scheduled` SÍ crea una
 *   sesión nueva (vía la misma autoridad, `TrainingEngine::decideNextSession()`),
 *   dentro del MISMO alcance de transacción/bloqueo (ver sección de
 *   concurrencia más abajo) — nunca fuera de él. El resultado nunca
 *   confunde ambos casos: `wasReplacement` distingue explícitamente
 *   "reemplazó algo real" de "creó porque no había nada que reemplazar".
 *
 * CONCURRENCIA — alcance exacto de `lockForUpdate()` y sus límites reales
 * (revisión final B2.3, punto 3; corrige una afirmación demasiado amplia de
 * una versión anterior de este docblock):
 *
 * La query bloqueada es `WHERE contact_id = ? AND status = 'scheduled'
 * FOR UPDATE`, sobre el índice compuesto `(contact_id, status)` ya existente
 * en `workout_sessions` (ver migración de creación de la tabla).
 *
 * - Caso "exactamente 1 `Scheduled` encontrada" (el camino de reemplazo real
 *   de `replace()`): SÍ hay una fila real que bloquear. Una segunda
 *   transacción concurrente que intente el mismo `SELECT ... FOR UPDATE`
 *   queda bloqueada por InnoDB hasta que la primera confirma; al
 *   desbloquearse, relee el dato ya confirmado (lectura de bloqueo bajo
 *   REPEATABLE READ) — la fila ya es `Superseded`, así que la segunda
 *   transacción ve CERO `Scheduled` para ese contacto. Este caso está bien
 *   protegido.
 * - Caso "CERO `Scheduled` encontradas": un `SELECT ... FOR UPDATE` que no
 *   matchea ninguna fila NO bloquea de forma garantizada un `INSERT`
 *   concurrente para el mismo `contact_id` — el bloqueo de "gap" de InnoDB
 *   sobre un predicado de igualdad indexado es el comportamiento típico,
 *   pero depende de la versión/motor exactos de MariaDB y del plan de
 *   consulta real, y NO es algo que este código verifique ni fuerce. Por
 *   eso `replaceOrCreate()` ejecuta la creación de respaldo DENTRO de la
 *   MISMA transacción que adquirió el (posible) bloqueo — estrictamente
 *   mejor que ejecutarla después y fuera de cualquier transacción (como
 *   hacía una versión anterior de este servicio), pero esta clase NO
 *   afirma que esto sea una garantía absoluta de "máximo 1 Scheduled" para
 *   el caso de 0 filas. `TrainingEngine::decideNextSession()` en sí mismo
 *   no usa ningún bloqueo (riesgo preexistente, documentado en la auditoría
 *   B2.1 sección 13, no introducido ni resuelto por este hito) — dos
 *   solicitudes "otra rutina" verdaderamente simultáneas de un contacto SIN
 *   ninguna sesión `Scheduled` todavía podrían, en un escenario de carrera
 *   real, producir dos `WorkoutSession` `Scheduled`. Este riesgo es
 *   idéntico al que YA existía para la creación normal de la primera sesión
 *   de cualquier contacto (fuera del alcance de B2) — B2 no lo agrava, pero
 *   tampoco lo cierra por completo. Ver auditoría/reporte de B2.3 para la
 *   evaluación explícita de por qué no se agrega un índice único todavía.
 *
 * Inconsistencia de datos (auditoría B2.1 sección 4 — no existe ninguna
 * constraint de base de datos que garantice como máximo 1 `Scheduled` por
 * `Contact`, solo la disciplina de aplicación): si se encuentran MÁS de una,
 * NUNCA se elige una arbitrariamente — se lanza
 * `MultipleActiveWorkoutSessionsException`, que el llamador debe manejar
 * explícitamente (ver `TrainingHandler`).
 */
class ReplaceWorkoutSessionService
{
    public function __construct(private readonly TrainingEngine $engine) {}

    /**
     * Responsabilidad ÚNICA (ver docblock de la clase, punto 2 de la
     * revisión B2.3): exactamente 1 `Scheduled` -> la reemplaza; 0 -> `null`
     * (NUNCA crea nada, NUNCA simula un reemplazo); >1 -> excepción. Quien
     * necesite "reemplaza, o crea si no hay nada que reemplazar" en una
     * sola operación segura debe usar `replaceOrCreate()`, no reconstruir
     * ese flujo llamando a este método y luego a
     * `TrainingEngine::decideNextSession()` por su cuenta fuera de
     * transacción (exactamente el patrón que `replaceOrCreate()`
     * reemplaza).
     *
     * @param  ?array<int, RequestedFocusGroup>  $newRequestedFocus  Foco
     *         puntual EXPLÍCITO del mensaje actual (ej. "dame otra rutina de
     *         pecho"), ya canonicalizado por `RequestedFocusTermMapper` —
     *         `null` si el mensaje no pidió ningún foco nuevo. Precedencia
     *         (diseño aprobado, Sección 9): si se provee, SIEMPRE gana sobre
     *         cualquier `requested_focus` heredado de la sesión reemplazada.
     * @return ?WorkoutSession La nueva sesión `Scheduled`, o `null` si no
     *         había ninguna `WorkoutSession` `Scheduled` que reemplazar.
     *
     * @throws MultipleActiveWorkoutSessionsException si el `Contact` tiene
     *         más de una `WorkoutSession` `Scheduled` — inconsistencia de
     *         datos que nunca se resuelve eligiendo una arbitrariamente.
     */
    public function replace(Contact $contact, ?array $newRequestedFocus): ?WorkoutSession
    {
        $result = $this->resolve($contact, $newRequestedFocus, createIfNone: false);

        return $result['wasReplacement'] ? $result['session'] : null;
    }

    /**
     * Punto de entrada real para el flujo conversacional de B2 (ver
     * `TrainingHandler`): reemplaza si hay algo que reemplazar, o crea una
     * sesión normal (misma autoridad, `TrainingEngine::decideNextSession()`)
     * si no había ninguna — ambos casos dentro del MISMO alcance de
     * transacción/bloqueo (ver sección de concurrencia del docblock de la
     * clase). `wasReplacement` distingue explícitamente ambos desenlaces —
     * nunca hay que inferirlo comparando IDs ni asumiendo nada.
     *
     * @param  ?array<int, RequestedFocusGroup>  $newRequestedFocus
     * @return array{session: WorkoutSession, wasReplacement: bool}
     *
     * @throws MultipleActiveWorkoutSessionsException
     * @throws \App\Training\Support\TrainingAccessDeniedException
     * @throws \App\Training\Support\TrainingCatalogInsufficientException
     */
    public function replaceOrCreate(Contact $contact, ?array $newRequestedFocus): array
    {
        return $this->resolve($contact, $newRequestedFocus, createIfNone: true);
    }

    /**
     * Núcleo compartido de `replace()`/`replaceOrCreate()` — única
     * implementación real de la transacción+bloqueo, para que ambas
     * entradas públicas compartan EXACTAMENTE el mismo alcance de lock
     * (nunca una versión "completa" y otra "recortada" del mismo mecanismo).
     *
     * @return array{session: ?WorkoutSession, wasReplacement: bool}
     */
    private function resolve(Contact $contact, ?array $newRequestedFocus, bool $createIfNone): array
    {
        return DB::transaction(function () use ($contact, $newRequestedFocus, $createIfNone) {
            $scheduledSessions = WorkoutSession::query()
                ->where('contact_id', $contact->id)
                ->where('status', WorkoutSessionStatus::Scheduled)
                ->lockForUpdate()
                ->get();

            if ($scheduledSessions->count() > 1) {
                throw new MultipleActiveWorkoutSessionsException($contact->id, $scheduledSessions->count());
            }

            if ($scheduledSessions->isEmpty()) {
                // Sección 7 del diseño aprobado — nada que reemplazar: NUNCA
                // se simula un reemplazo. `$createIfNone` decide si además
                // corresponde crear una sesión normal AQUÍ MISMO (dentro de
                // la misma transacción que adquirió el lock) o dejarlo en
                // manos del llamador (`replace()` puro).
                if (! $createIfNone) {
                    return ['session' => null, 'wasReplacement' => false];
                }

                $session = $this->engine->decideNextSession($contact, $newRequestedFocus);

                return ['session' => $session, 'wasReplacement' => false];
            }

            $oldSession = $scheduledSessions->first();

            // Sección 9/11 del diseño aprobado — precedencia: explícito >
            // heredado de la sesión reemplazada > null (foco autónomo).
            $effectiveRequestedFocus = $newRequestedFocus ?? $this->inheritedRequestedFocus($oldSession);

            // Cierre ANTES de invocar TrainingEngine — ver docblock de la
            // clase: esto es lo único que hace que decideNextSession() deje
            // de ser idempotente hacia la sesión vieja y cree una genuina.
            $oldSession->update(['status' => WorkoutSessionStatus::Superseded]);

            $newSession = $this->engine->decideNextSession($contact, $effectiveRequestedFocus);

            $oldSession->update(['superseded_by_id' => $newSession->id]);

            return ['session' => $newSession, 'wasReplacement' => true];
        });
    }

    /**
     * Hito B2 (diseño aprobado, Sección 11) — reconstruye
     * `RequestedFocusGroup[]` a partir de
     * `WorkoutSession.prescription_context_snapshot.requested_focus` de la
     * sesión reemplazada. NUNCA vuelve a analizar texto ni invoca
     * `RequestedFocusTermMapper` (ese mapeador traduce TÉRMINOS CRUDOS del
     * usuario — el snapshot ya contiene datos canónicos, serializados por
     * `TrainingEngine::decideNextSession()` exactamente en el shape
     * `{key, muscles}` que `RequestedFocusGroup` exige) — reconstruir es una
     * operación directa, sin ningún parsing nuevo que duplicar.
     *
     * `[]`/ausencia de la clave (snapshot anterior a B1, o sesión sin
     * requested_focus) produce `[]` aquí, que `TrainingEngine::
     * decideNextSession()` ya normaliza a `null` (mismo criterio que
     * siempre: un array vacío es semánticamente idéntico a "no se
     * solicitó nada").
     *
     * @return array<int, RequestedFocusGroup>
     */
    private function inheritedRequestedFocus(WorkoutSession $oldSession): array
    {
        $snapshot = $oldSession->prescription_context_snapshot ?? [];
        $requestedFocus = $snapshot['requested_focus'] ?? [];

        if (! is_array($requestedFocus) || $requestedFocus === []) {
            return [];
        }

        return array_map(
            fn (array $group) => new RequestedFocusGroup($group['key'], $group['muscles']),
            $requestedFocus,
        );
    }
}
