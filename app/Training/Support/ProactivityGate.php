<?php

namespace App\Training\Support;

use App\Models\Contact;

/**
 * P1-A — abstracción única y reutilizable para "¿puede este contacto recibir
 * un mensaje proactivo de este tipo ahora mismo?", pensada para crecer hacia
 * un arbitraje real entre varios tipos de evento (`exercise_nudge` hoy;
 * `trial_expiring_soon`/`membership_expiring_soon`/`inactivity` en el
 * futuro) sin que ningún detector necesite cambiar su forma de consultarla.
 *
 * Deliberadamente NO implementa todavía ningún cooldown global entre tipos
 * de evento — decisión de negocio explícita (auditoría P1-A): con un solo
 * tipo de evento existente, no hay nada real con lo que `exercise_nudge`
 * pueda competir todavía, así que inventar un número de horas ahora sería
 * una constante sin ningún caso que la justifique. Cuando se incorpore un
 * segundo tipo de evento, este método (y solo este método — el detector y
 * el Command no necesitan cambiar) es el único lugar que debe ganar esa
 * regla.
 *
 * Nunca decide QUÉ decir ni CUÁNDO calcular candidatos — eso es
 * responsabilidad exclusiva de cada detector (`UnreportedExerciseDetector`
 * hoy). Esta clase solo responde sí/no.
 */
class ProactivityGate
{
    public function canSend(Contact $contact, string $eventKey): bool
    {
        return true;
    }
}
