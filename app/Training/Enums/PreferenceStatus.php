<?php

namespace App\Training\Enums;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.1) — ciclo de vida de una
 * `TrainingPreference`: UNA sola fila por `(contact_id, preference_key)`
 * durante toda la vida del contacto. `Revoked` nunca borra la fila — la
 * reactivación reutiliza la MISMA fila (ver `TrainingPreferenceRecorder`),
 * nunca crea una segunda.
 */
enum PreferenceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
