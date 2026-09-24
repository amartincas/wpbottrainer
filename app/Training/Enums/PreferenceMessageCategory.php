<?php

namespace App\Training\Enums;

/**
 * Hito B3 (diseño v3 FINAL, Secciones 1/2/A.2) — las 6 categorías léxicas
 * cerradas que `TrainingPreferenceMessageClassifier` puede producir a partir
 * de texto crudo (regex, sin IA). `None` es un séptimo estado implícito
 * (ningún marcador coincidió) — deliberadamente NO es un case de este enum:
 * el clasificador devuelve `category: null` en ese caso (ver
 * `TrainingPreferenceClassification`), para no confundirlo con `Ambiguous`
 * (que SÍ significa "se detectó algo relevante, pero no se puede resolver
 * con seguridad" — dispara clarificación; `None` nunca dispara nada).
 *
 * Precedencia fija (nunca por descarte, nunca decidida por la IA):
 * Safety > Temporal > Permanence > InstanceAnchor > Dislike > ActionRefusal
 * > Ambiguous.
 */
enum PreferenceMessageCategory: string
{
    /** "no puedo... porque me duele/me lastimé/me operaron/lesión/cirugía". */
    case Safety = 'safety';

    /** "hoy no quiero...", "por ahora...", "esta semana..." — nunca persiste. */
    case Temporal = 'temporal';

    /** "no quiero volver a...", "ya no quiero...", "nunca más...". */
    case Permanence = 'permanence';

    /** "esta"/"esa"/"la"/"lo" anclando una instancia puntual — nunca genera Preference. */
    case InstanceAnchor = 'instance_anchor';

    /** "no me gusta(n)...", "prefiero no...", "no soy fan de...", "no es/son lo mío". */
    case Dislike = 'dislike';

    /** "no quiero hacer/usar X" sin ningún otro marcador — depende del contexto (Main pendiente). */
    case ActionRefusal = 'action_refusal';

    /** Forma reconocida pero irresoluble sin más información — dispara clarificación. */
    case Ambiguous = 'ambiguous';
}
