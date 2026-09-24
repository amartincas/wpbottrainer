<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\TrainingPreference;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;

/**
 * Hito B3 (diseño v3 FINAL, Sección F/17) — única pieza autorizada a leer
 * `TrainingPreference` directamente para efectos de selección, mismo
 * espíritu exacto que `SafetyRestrictionResolver`: `TrainingEngine` nunca ve
 * la entidad cruda, solo el resultado ya nivelado de este resolver.
 */
class TrainingPreferenceResolver
{
    /**
     * @return array{exercise_ids: array<int, int>, equipment: array<int, string>}
     */
    public function excludedIdentifiersFor(Contact $contact): array
    {
        $active = TrainingPreference::query()
            ->where('contact_id', $contact->id)
            ->where('status', PreferenceStatus::Active)
            ->get();

        return [
            'exercise_ids' => $active->where('dimension', PreferenceDimension::Exercise)
                ->pluck('exercise_id')->filter()->values()->all(),
            'equipment' => $active->where('dimension', PreferenceDimension::Equipment)
                ->pluck('equipment_value')->filter()->values()->all(),
        ];
    }

    /**
     * Hito B3 (diseño v3 FINAL, Sección 17/15) — etiquetas legibles de las
     * preferencias ACTIVAS de un contacto, exclusivamente para exponerlas
     * como FACT al Coach (ver `CoachFactsFormatter`) — nunca usado por
     * `TrainingEngine`, que solo consume `excludedIdentifiersFor()`.
     *
     * @return array<int, string>
     */
    public function activeLabelsFor(Contact $contact): array
    {
        return TrainingPreference::query()
            ->where('contact_id', $contact->id)
            ->where('status', PreferenceStatus::Active)
            ->with('exercise')
            ->get()
            ->map(fn (TrainingPreference $preference) => $preference->dimension === PreferenceDimension::Exercise
                ? ($preference->exercise?->name_es ?? $preference->exercise?->name ?? $preference->original_text)
                : $preference->equipment_value)
            ->filter()
            ->values()
            ->all();
    }
}
