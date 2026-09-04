<?php

namespace App\Training\Enums;

/**
 * Hito 9 — vocabulario cerrado de equipamiento, compartido por ambos lados
 * de la comparación que ya hacía TrainingEngine::isEligible()
 * (Exercise.equipment_needed vs. TrainingProfile.available_equipment):
 * antes de este enum, ambos eran arrays de string libre que solo
 * coincidían porque los datos de prueba/onboarding usaban las mismas
 * palabras informalmente. Con contenido real de proveedor (YMove usa su
 * propio vocabulario en inglés) esa coincidencia accidental deja de
 * sostenerse — este enum es el punto único de traducción para ambos lados:
 * cada `ExerciseNormalizerInterface` lo usa para `equipment_needed`.
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
    // OFICIAL y completo de equipamiento de YMove (GET /exercises/equipment,
    // endpoint de metadata sin costo de cuota, 22 valores reales auditados)
    // — antes de esto, cualquier ejercicio que usara uno de estos 13 caía
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
}
