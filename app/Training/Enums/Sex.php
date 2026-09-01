<?php

namespace App\Training\Enums;

/**
 * Hito 8.3 — dato contextual capturado desde MVP (docs/DECISIONS.md).
 * Deliberadamente sin ningún consumidor en App\Training\Engine\TrainingEngine
 * todavía — no existe base científica sólida para reglas de selección de
 * ejercicio por sexo, y no se inventan aquí. `PreferNotToSay` es una
 * respuesta válida y completa, no un valor "faltante".
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
    case PreferNotToSay = 'prefer_not_to_say';
}
