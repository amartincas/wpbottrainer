<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\TrainingPreference;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.1/8) — única autoridad de escritura de
 * `TrainingPreference`. `TrainingHandler` nunca manipula la entidad
 * directamente (Regla 15 del encargo de implementación); `TrainingEngine`
 * nunca escribe preferencias (Regla 14).
 *
 * Lifecycle exacto, UNA sola fila por `(contact_id, preference_key)`
 * durante toda la vida del contacto — nunca una segunda fila para el mismo
 * valor, activa o revocada:
 * - Sin fila previa -> INSERT, `status=active`.
 * - Fila ya `active` con el mismo valor -> no-op puro, NINGÚN write (ni
 *   siquiera se toca `original_text`).
 * - Fila `revoked` -> se REACTIVA la MISMA fila (`status=active`,
 *   `reactivated_at=now()`, `original_text` se sobrescribe con la
 *   declaración más reciente; `revoked_at` se preserva como historia de la
 *   última revocación, nunca se limpia).
 *
 * Regla dura (Regla 10 del encargo): esta clase NUNCA se invoca desde
 * `ExerciseLog`/`skip_reason=dont_want` — el único origen válido en el MVP
 * es una declaración conversacional explícita, siempre vía
 * `TrainingPreferenceMessageClassifier` + `TrainingPreferenceIdentityResolver`.
 */
class TrainingPreferenceRecorder
{
    public function persistExercisePreference(Contact $contact, int $exerciseId, string $originalText): TrainingPreference
    {
        return $this->persist($contact, PreferenceDimension::Exercise, "exercise:{$exerciseId}", $exerciseId, null, $originalText);
    }

    public function persistEquipmentPreference(Contact $contact, string $equipmentValue, string $originalText): TrainingPreference
    {
        return $this->persist($contact, PreferenceDimension::Equipment, "equipment:{$equipmentValue}", null, $equipmentValue, $originalText);
    }

    private function persist(
        Contact $contact,
        PreferenceDimension $dimension,
        string $preferenceKey,
        ?int $exerciseId,
        ?string $equipmentValue,
        string $originalText,
    ): TrainingPreference {
        $existing = TrainingPreference::query()
            ->where('contact_id', $contact->id)
            ->where('preference_key', $preferenceKey)
            ->first();

        if ($existing === null) {
            return TrainingPreference::create([
                'contact_id' => $contact->id,
                'dimension' => $dimension,
                'exercise_id' => $exerciseId,
                'equipment_value' => $equipmentValue,
                'preference_key' => $preferenceKey,
                'status' => PreferenceStatus::Active,
                'original_text' => $originalText,
                'created_at' => now(),
            ]);
        }

        if ($existing->status === PreferenceStatus::Active) {
            // Redeclaración idéntica de una preferencia ya activa: no-op
            // puro, ningún write (Sección A.1 de la revisión v3).
            return $existing;
        }

        $existing->update([
            'status' => PreferenceStatus::Active,
            'reactivated_at' => now(),
            'original_text' => $originalText,
        ]);

        return $existing->fresh();
    }

    /**
     * Revoca una preferencia activa. No-op si ya está revocada o no existe
     * (nunca lanza — un intento de revocar algo que ya no aplica no es un
     * error operativo).
     */
    public function revoke(Contact $contact, PreferenceDimension $dimension, ?int $exerciseId, ?string $equipmentValue): void
    {
        $preferenceKey = $dimension === PreferenceDimension::Exercise
            ? "exercise:{$exerciseId}"
            : "equipment:{$equipmentValue}";

        TrainingPreference::query()
            ->where('contact_id', $contact->id)
            ->where('preference_key', $preferenceKey)
            ->where('status', PreferenceStatus::Active)
            ->update(['status' => PreferenceStatus::Revoked, 'revoked_at' => now()]);
    }
}
