<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\TrainingPreferenceClarification;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\TrainingPreferenceClarificationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Hito B3.1 — única autoridad de escritura de `TrainingPreferenceClarification`.
 * Deliberadamente separada de `PendingPreferenceClarificationResolver`, que
 * es PURO (nunca toca la base de datos, ver su docblock) — esta clase nunca
 * resuelve identidad, solo persiste transiciones de lifecycle ya decididas
 * por el llamador (`TrainingHandler`). Mismo criterio de separación que ya
 * existe entre `TrainingPreferenceIdentityResolver` (resuelve) y
 * `TrainingPreferenceRecorder` (persiste).
 *
 * Ningún registro se destruye nunca — `resolve()`/`abandon()` son
 * transiciones de `status`, nunca un DELETE.
 */
class TrainingPreferenceClarificationRecorder
{
    /**
     * Hito 10 (`ReminderSuggestion`) ya estableció este mismo valor para el
     * único precedente real de "estado conversacional pendiente" del
     * proyecto — se reutiliza aquí sin inventar un TTL nuevo (Regla del
     * encargo B3.1: "no introduzcas un TTL arbitrario distinto del diseño
     * aprobado").
     */
    private const TTL_HOURS = 24;

    /**
     * Crea una nueva `TrainingPreferenceClarification` `pending` para el
     * contacto. Si ya existía una `pending` (turno inicial con una nueva
     * declaración ambigua mientras una clarificación anterior seguía sin
     * responder, o una respuesta ambigua a la clarificación actual —
     * diseño v3 aprobado, Parte 3/6), esa fila previa se abandona primero,
     * en la MISMA transacción — nunca dos `pending` simultáneas para el
     * mismo contacto (reforzado además por el índice único de la
     * migración).
     *
     * @param  array<int, string>  $presentedOptions
     */
    public function create(
        Contact $contact,
        PreferenceDimension $dimension,
        ?string $candidateTerm,
        string $originalText,
        array $presentedOptions,
        int $totalMatches,
    ): TrainingPreferenceClarification {
        return DB::transaction(function () use ($contact, $dimension, $candidateTerm, $originalText, $presentedOptions, $totalMatches) {
            $this->abandonActiveFor($contact);

            return TrainingPreferenceClarification::create([
                'contact_id' => $contact->id,
                'dimension' => $dimension,
                'original_candidate_term' => $candidateTerm,
                'original_text' => $originalText,
                'presented_options' => $presentedOptions,
                'total_matches' => $totalMatches,
                'status' => TrainingPreferenceClarificationStatus::Pending,
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Marca la clarificación como resuelta — se invoca únicamente cuando
     * `PendingPreferenceClarificationResolver` devolvió `resolved` Y la
     * `TrainingPreference` correspondiente ya fue persistida (el llamador,
     * `TrainingHandler`, garantiza ese orden). No-op si la fila ya no está
     * `pending` (nunca lanza — mismo criterio que `TrainingPreferenceRecorder::revoke()`).
     */
    public function resolve(TrainingPreferenceClarification $pending): void
    {
        if ($pending->status !== TrainingPreferenceClarificationStatus::Pending) {
            return;
        }

        $pending->update([
            'status' => TrainingPreferenceClarificationStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Abandona la `pending` activa del contacto, si existe. No-op si no hay
     * ninguna (nunca lanza). Se usa tanto internamente por `create()` como
     * directamente por `TrainingHandler` cuando una nueva preferencia se
     * resuelve de forma inmediata mientras una clarificación anterior
     * seguía sin responder (diseño v3 aprobado, Parte 6 paso 3).
     */
    public function abandonActiveFor(Contact $contact): void
    {
        TrainingPreferenceClarification::query()
            ->where('contact_id', $contact->id)
            ->where('status', TrainingPreferenceClarificationStatus::Pending)
            ->update([
                'status' => TrainingPreferenceClarificationStatus::Abandoned,
                'abandoned_at' => now(),
            ]);
    }
}
