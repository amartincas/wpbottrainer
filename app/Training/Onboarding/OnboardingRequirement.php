<?php

namespace App\Training\Onboarding;

use App\Models\Contact;
use App\Models\TrainingProfile;

/**
 * Bloque 4 — pieza LEGO de onboarding: el SISTEMA decide QUÉ información
 * necesita (esta interfaz); la IA decide CÓMO formularlo (ver
 * OnboardingConversationComposer/OnboardingConversationService). Ninguna
 * implementación depende de un proveedor de IA concreto ni de
 * `AiServiceInterface`.
 *
 * Contrato final (Bloque 4, tras inspección del código real — 3 métodos
 * añadidos al contrato originalmente propuesto):
 * - `extractedKeys()`: algunos requirements poseen más de una clave del JSON
 *   de extracción combinado (ej. Equipment: available_equipment +
 *   equipment_fully_equipped; PhysicalStats: 4 claves) — un solo valor
 *   escalar no alcanza para modelarlos.
 * - `validate()`/`apply()` reciben/devuelven el slice `array<string,mixed>`
 *   de esas claves, no `mixed $rawValue`.
 * - `onAsked()`: se invoca cuando ESTE requirement fue el elegido para
 *   preguntarse en el turno actual (principal o secundario/oportunista),
 *   sin importar si el usuario respondió algo. Sin él,
 *   `PhysicalStatsRequirement` no puede reproducir "se pregunta una sola
 *   vez, sin importar la respuesta" — no-op para el resto.
 */
interface OnboardingRequirement
{
    /**
     * Identificador estable, compartido con las claves ya existentes de
     * `OnboardingConversationService::FALLBACK_QUESTIONS`/`NEXT_ACTION_MAP`
     * — el vocabulario de campos no cambia en este bloque.
     */
    public function key(): string;

    /**
     * @return array<int, string> claves de `extracted` (JSON de la IA) que
     *         este requirement posee.
     */
    public function extractedKeys(): array;

    /**
     * Estático para los 9 requirements de este bloque (siempre true o
     * siempre false) — el parámetro existe para que un futuro
     * `HealthScreeningRequirement` pueda ser condicional sin cambiar el
     * contrato.
     */
    public function isBlocking(TrainingProfile $profile, Contact $contact): bool;

    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool;

    /**
     * @param  array<string, mixed>  $rawValues  slice de `extracted`, solo
     *         las claves de `extractedKeys()`.
     * @return array<string, mixed>
     */
    public function validate(array $rawValues): array;

    /**
     * @param  array<string, mixed>  $validatedValues
     */
    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void;

    public function onAsked(Contact $contact, TrainingProfile $profile): void;

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext;
}
