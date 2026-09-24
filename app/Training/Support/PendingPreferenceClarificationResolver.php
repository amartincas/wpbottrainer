<?php

namespace App\Training\Support;

use App\Models\TrainingPreferenceClarification;

/**
 * Hito B3.1 — PURO por diseño (aprobación explícita del encargo): dado el
 * texto de un turno posterior a una `TrainingPreferenceClarification`
 * `pending`, decide si ese texto identifica un ejercicio, delegando POR
 * COMPLETO en `TrainingPreferenceIdentityResolver::resolve()` (API pública,
 * sin ningún cambio) — nunca reimplementa ni relaja sus reglas de identidad
 * (exact match, singular/plural, partial matching para clarificación
 * únicamente; nunca fuzzy, sinónimos, stemming, reorder).
 *
 * NO hace, y nunca debe hacer, ninguna de estas cosas (Regla del encargo):
 * - consultar `ConversationAction`/`ConversationActionType`;
 * - consultar `CoachService`/`ConversationTurnResolver`;
 * - leer o escribir en base de datos;
 * - crear, resolver o abandonar ninguna `TrainingPreferenceClarification`;
 * - crear ninguna `TrainingPreference`;
 * - tocar `TrainingProfile` ni ningún otro modelo.
 *
 * El lifecycle/persistencia de la pending (crear la siguiente, marcarla
 * resuelta, abandonarla) es responsabilidad exclusiva de
 * `TrainingPreferenceClarificationRecorder`, invocado por `TrainingHandler`
 * — nunca desde aquí.
 *
 * Sin guardia de longitud ni ninguna otra heurística sobre `$responseBody`
 * (decisión v3 del diseño aprobado, Punto 1/B): la seguridad viene del
 * propio contrato de `resolve()` (`resolved` es estructuralmente
 * inalcanzable para una frase con relleno conversacional; `clarify`/
 * `unresolved` nunca persisten nada), no de un pre-filtro sobre el texto.
 *
 * `$pending` se recibe por completitud de firma (documentado en el diseño
 * aprobado) pero NUNCA se lee dentro de este método — el diseño v3 (Punto 4)
 * determinó explícitamente que concatenar `original_candidate_term` con la
 * respuesta no es necesaria para el MVP y arriesgaría violar la regla de
 * "nunca eliminar preposiciones internas" ya documentada en
 * `TrainingPreferenceIdentityResolver`.
 */
class PendingPreferenceClarificationResolver
{
    public function __construct(private readonly TrainingPreferenceIdentityResolver $identityResolver) {}

    public function resolve(TrainingPreferenceClarification $pending, string $responseBody): PendingClarificationOutcome
    {
        $resolution = $this->identityResolver->resolve($responseBody, null);

        return match ($resolution->status) {
            'resolved' => PendingClarificationOutcome::resolved($resolution),
            'clarify' => PendingClarificationOutcome::ambiguous($resolution),
            default => PendingClarificationOutcome::noMatch(),
        };
    }
}
