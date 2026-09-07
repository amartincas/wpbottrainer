<?php

namespace App\Training\Enums;

/**
 * Hito 10 — distingue por qué existe una `ReminderSuggestion`, para poder
 * analizar después el origen y evitar repetir una misma oferta proactiva en
 * exceso (ver `ReminderProactivityGate`). Deliberadamente un enum cerrado de
 * solo 2 valores — no una tercera entidad — mientras que `trigger_reason`
 * (string libre, solo relevante cuando origin=Proactive) guarda CUÁL de los
 * triggers de proactividad la generó.
 */
enum ReminderSuggestionOrigin: string
{
    case UserRequest = 'user_request';
    case Proactive = 'proactive';
}
