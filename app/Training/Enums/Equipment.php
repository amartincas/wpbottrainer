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
 * cada `ExerciseNormalizerInterface` lo usa para `equipment_needed`, y
 * `OnboardingConversationService` lo usa para `available_equipment`.
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
}
