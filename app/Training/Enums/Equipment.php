<?php

namespace App\Training\Enums;

/**
 * Hito 9 — vocabulario cerrado de equipamiento, compartido por ambos lados
 * de la comparación que ya hacía TrainingEngine::isEligible()
 * (Exercise.equipment_needed vs. TrainingProfile.available_equipment):
 * antes de este enum, ambos eran arrays de string libre que solo
 * coincidían porque los datos de prueba/onboarding usaban las mismas
 * palabras informalmente. Con contenido real de proveedor (cada proveedor
 * externo usa su propio vocabulario, típicamente en inglés) esa
 * coincidencia accidental deja de sostenerse — este enum es el punto único
 * de traducción para ambos lados: cada `ExerciseNormalizerInterface` lo
 * usa para `equipment_needed`. Este archivo es 100% vocabulario de
 * dominio — nunca menciona un proveedor concreto (ver
 * MultiProviderIsolationArchTest).
 *
 * Hito 9.3 (post-deploy, corrección) — hallazgo real: este docblock decía
 * que `OnboardingConversationService` YA usaba este enum para
 * `available_equipment` — era falso, nunca se implementó ese lado
 * (confirmado: cero referencias a esta clase en ese archivo antes de esta
 * corrección). El enum se diseñó completo, pero solo se cableó la mitad.
 * Ahora sí: `OnboardingConversationService::validateEquipmentArray()`
 * completa el otro lado, con el mismo patrón que `MuscleFocus`.
 */
enum Equipment: string
{
    case Barbell = 'barbell';
    case Dumbbells = 'dumbbells';
    case Kettlebell = 'kettlebell';
    case CableMachine = 'cable_machine';
    case Machine = 'machine';
    case ResistanceBands = 'resistance_bands';
    case Bench = 'bench';
    case PullUpBar = 'pull_up_bar';
    case MedicineBall = 'medicine_ball';

    // Hito 9.3 (post-deploy) — completa el vocabulario contra el catálogo
    // OFICIAL y completo de equipamiento del proveedor principal en
    // producción (22 valores reales auditados, ver docs/DECISIONS.md) —
    // antes de esto, cualquier ejercicio que usara uno de estos 13 caía
    // silenciosamente en "sin equipo" (hallazgo real, ver docs/DECISIONS.md).
    case Mat = 'mat';
    case Chair = 'chair';
    case Box = 'box';
    case WeightedVest = 'weighted_vest';
    case SmithMachine = 'smith_machine';
    case StabilityBall = 'stability_ball';
    case Wall = 'wall';
    case Cone = 'cone';
    case FreeWeights = 'free_weights';
    case Landmine = 'landmine';
    case FoamRoller = 'foam_roller';
    case Step = 'step';
    case Towel = 'towel';

    // Hito Provider-Agnostic Normalization — 6 valores de equipo real
    // encontrados en vivo en el catálogo del proveedor principal en
    // producción, sin equivalente hasta ahora (ver docs/DECISIONS.md,
    // Audit #4): cada uno es un objeto físicamente distinto de cualquier
    // valor ya existente, confirmado contra ejemplos reales del catálogo
    // antes de agregarlo — nunca se agregó automáticamente "todo lo nuevo
    // que apareciera".
    case Rings = 'rings';
    case DipBar = 'dip_bar';
    case Bosu = 'bosu';
    case Plate = 'plate';
    case SuspensionTrainer = 'suspension_trainer';
    case BattleRope = 'battle_rope';

    /**
     * Hito Provider-Agnostic Normalization — NO es un equipo físico. Es un
     * sentinel de dominio: significa "un proveedor declaró un requisito de
     * equipamiento que este dominio todavía no puede representar" —
     * explícitamente distinto de "no requiere equipo" (`equipment_needed
     * === []`). Existe para que el `ExerciseNormalizerInterface` de
     * cualquier proveedor (el actual en producción o uno futuro) nunca
     * tenga que elegir entre "inventar una equivalencia" y "fingir que no
     * hace falta nada" ante un valor crudo desconocido — ver
     * TrainingEngine::isEligible(), que lo trata como un caso aparte,
     * antes que cualquier otra regla de equipo.
     *
     * Reglas duras, sin excepción:
     * - Nunca lo produce el onboarding (su vocabulario cerrado, hardcodeado
     *   en OnboardingConversationService, no lo incluye ni puede incluirlo
     *   por accidente).
     * - Nunca aparece en `TrainingProfile.available_equipment` de un
     *   usuario real.
     * - Nunca hace elegible un ejercicio, bajo ninguna combinación de
     *   ubicación/equipamiento declarado, ni siquiera
     *   `equipment_fully_equipped=true` ("tengo de todo" es una afirmación
     *   sobre equipo REAL conocido, nunca sobre un requisito que ni
     *   siquiera sabemos identificar).
     */
    case Unsupported = 'unsupported';
}
