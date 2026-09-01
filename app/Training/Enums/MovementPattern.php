<?php

namespace App\Training\Enums;

/**
 * Hito 8.4 — patrón de movimiento del ejercicio. Agregado ahora para que el
 * catálogo real del Hito 9 no requiera otra migración; el scoring de este
 * hito NO lo consume todavía (deliberadamente, para no sobre-alcanzar sin
 * contenido real que lo respalde) — ver docs/DECISIONS.md.
 */
enum MovementPattern: string
{
    case Squat = 'squat';
    case Hinge = 'hinge';
    case Push = 'push';
    case Pull = 'pull';
    case Lunge = 'lunge';
    case Carry = 'carry';
    case Core = 'core';
    case Isolation = 'isolation';
}
