<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones. Representa EXCLUSIVAMENTE el origen
 * de la información — nunca quién la confirmó. La confirmación humana vive
 * en TrainingRestriction.reviewed_by/reviewed_at, ortogonal a este campo.
 * `source` nunca cambia después de creado el registro, incluso si un
 * humano lo revisa y confirma después.
 */
enum RestrictionSource: string
{
    case UserExplicit = 'user_explicit';
    case UserVague = 'user_vague';
    case ProfessionalReportedByUser = 'professional_reported_by_user';
    case SystemRule = 'system_rule';
}
