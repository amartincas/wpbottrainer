<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones (Bloque 2) — clasificación LIGERA de
 * una `DeclaredHealthCondition`, provista por quien detecta la declaración
 * (futuro `HealthScreeningRequirement`, Bloque 4) — nunca calculada por
 * este bloque. Nunca decide ni sugiere una acción sobre `TrainingRestriction`
 * por sí sola.
 */
enum HealthConditionCategory: string
{
    case PossibleInjury = 'possible_injury';
    case PossibleRecovery = 'possible_recovery';
    case ProfessionalIndication = 'professional_indication';
}
