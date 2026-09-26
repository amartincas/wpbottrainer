<?php

namespace App\Training\Enums;

/**
 * Hito D (diseño formal v2 aprobado) — clasificación de dominio,
 * ORTOGONAL a `TrackingType` (reps vs. tiempo): si un `Exercise` espera
 * que su ejecución real reporte una carga externa (`Required`) o nunca la
 * espera (`None`, bodyweight puro). Vive en `Exercise.load_modality`
 * (nullable — `NULL` significa "sin clasificar", un estado de catálogo,
 * nunca una tercera clasificación real, ver docblock de la migración).
 *
 * Deliberadamente solo 2 casos (decisión cerrada en el diseño v2, sección
 * 2/8): el catálogo real no demuestra ningún caso que exija una tercera
 * modalidad ("Optional") — el proveedor ya modela variantes cargadas como
 * filas de `Exercise` separadas (ej. "Weighted Vest Plank" vs. "Bear
 * plank"), nunca como un mismo ejercicio con carga condicional.
 */
enum LoadModality: string
{
    case None = 'none';
    case Required = 'required';
}
