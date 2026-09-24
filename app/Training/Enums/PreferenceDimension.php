<?php

namespace App\Training\Enums;

/**
 * Hito B3 (diseño v3 FINAL, Sección 2) — dimensiones soportadas en el MVP.
 * `MuscleFocus`/`ExerciseType` quedan deliberadamente FUERA (diferidas a
 * B3.1: mayor riesgo de catálogo insuficiente y tensión sin resolver con la
 * rotación autónoma de `TrainingEngine::decideFocus()`) — nunca se agrega un
 * caso aquí sin ese análisis de precedencia hecho primero.
 */
enum PreferenceDimension: string
{
    case Exercise = 'exercise';
    case Equipment = 'equipment';
}
