<?php

namespace App\Training\Onboarding;

use App\Models\Contact;
use App\Models\TrainingProfile;
use Illuminate\Contracts\Container\Container;

/**
 * Bloque 4 — única autoridad sobre QUÉ preguntar y en qué orden. Mismo
 * patrón de resolución que App\Core\Messaging\Router/Dispatcher
 * (Registry → Container → Requirement, mapa de CLASES no instancias,
 * registrado en AppServiceProvider) — agregar un requirement nuevo es una
 * clase + una línea aquí, sin tocar TrainingHandler/TrainingEngine.
 *
 * El orden del array ES la prioridad — no existe un método de "orden" en
 * la interfaz `OnboardingRequirement` (ver docblock de la interfaz).
 */
class OnboardingRequirementRegistry
{
    /**
     * @param  array<int, class-string<OnboardingRequirement>>  $requirementClasses  en orden de prioridad
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $requirementClasses,
    ) {}

    /**
     * @return array<int, OnboardingRequirement>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), $this->requirementClasses);
    }

    public function find(string $key): ?OnboardingRequirement
    {
        foreach ($this->all() as $requirement) {
            if ($requirement->key() === $key) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * Reemplaza TrainingProfile::firstMissingOnboardingField() como
     * autoridad real usada por TrainingHandler — a diferencia de aquel,
     * ignora los requirements no bloqueantes (primary_focus/
     * sessions_per_week/physical_stats).
     */
    public function firstPendingBlocking(TrainingProfile $profile, Contact $contact): ?OnboardingRequirement
    {
        foreach ($this->all() as $requirement) {
            if ($requirement->isBlocking($profile, $contact) && ! $requirement->isSatisfied($profile, $contact)) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * Solo mira requirements bloqueantes — un oportunista sin responder
     * (ej. primary_focus) nunca impide generar la primera rutina.
     */
    public function isOnboardingComplete(TrainingProfile $profile, Contact $contact): bool
    {
        return $this->firstPendingBlocking($profile, $contact) === null;
    }

    /**
     * Política de turnos progresivos DEFINITIVA (Bloque 4, corregida tras un
     * bug real de umbral encontrado por inspección directa —
     * ver docs/DECISIONS.md D047):
     * - Turnos 1 y 2: nunca se invita a un oportunista — "primera y segunda
     *   interacción: contexto esencial" puro. Ningún chequeo de
     *   `isOnboardingComplete()` participa en esta regla: el umbral es
     *   ÚNICAMENTE `turnNumber`.
     * - Turno 3 en adelante: se invita al primer requirement oportunista
     *   todavía sin responder (en orden de registro:
     *   sessions_per_week → primary_focus → physical_stats), como pregunta
     *   SECUNDARIA junto a la principal (si aún hay una bloqueante
     *   pendiente) — nunca exige respuesta, nunca sostiene un turno
     *   adicional solo por esto.
     *
     * (Historial: una versión anterior usaba el umbral `turnNumber < 2`,
     * basada en un razonamiento incorrecto de que distinguir por estado de
     * blocking era "estructuralmente irrealizable". Verificado con datos
     * reales: SÍ era realizable, y el umbral correcto es 3, no 2 — ver
     * docs/DECISIONS.md D047 para el detalle completo del hallazgo.)
     */
    public function secondaryOpportunisticFor(int $turnNumber, TrainingProfile $profile, Contact $contact): ?OnboardingRequirement
    {
        if ($turnNumber < 3) {
            return null;
        }

        foreach ($this->all() as $requirement) {
            if (! $requirement->isBlocking($profile, $contact) && ! $requirement->isSatisfied($profile, $contact)) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * Reemplaza TrainingHandler::applyExtractedFields(). Por cada
     * requirement registrado, arma el slice de sus extractedKeys() desde
     * `$extracted` — si TODAS esas claves son null (nada mencionado este
     * turno), se omite por completo; si al menos una tiene valor (incluido
     * `[]`/`false`, que SÍ son respuestas válidas), se valida y se aplica.
     *
     * @param  array<string, mixed>  $extracted  el array "extracted" ya
     *         devuelto (y ya validado por tipo) por
     *         OnboardingConversationService::extractAndRespond().
     */
    public function applyExtracted(Contact $contact, TrainingProfile $profile, array $extracted): void
    {
        foreach ($this->all() as $requirement) {
            $slice = [];
            $hasAnyValue = false;

            foreach ($requirement->extractedKeys() as $extractedKey) {
                $value = array_key_exists($extractedKey, $extracted) ? $extracted[$extractedKey] : null;
                $slice[$extractedKey] = $value;

                if ($value !== null) {
                    $hasAnyValue = true;
                }
            }

            if (! $hasAnyValue) {
                continue;
            }

            $requirement->apply($contact, $profile, $requirement->validate($slice));
        }
    }
}
