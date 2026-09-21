<?php

namespace App\Training\Support;

/**
 * Hito R1/R2/R3 — lanzada por `TrainingEngine::decideNextSession()` cuando
 * el catálogo elegible no produce ni un solo ejercicio de bloque principal
 * (`WorkoutExercisePhase::Main`) para este perfil/ubicación/equipamiento.
 * Una `WorkoutSession` NUNCA debe crearse sin al menos 1 ejercicio Main —
 * esta excepción se lanza ANTES de `WorkoutSession::create()`, así que
 * ninguna fila llega a persistirse.
 *
 * Deliberadamente una clase separada de `TrainingAccessDeniedException`
 * (mismo shape/patrón, nunca la misma clase): el acceso comercial del
 * contacto es válido en este caso — el problema es que el catálogo no
 * tiene contenido elegible, no que se le esté negando el acceso. Reutilizar
 * `TrainingAccessDeniedException` produciría un mensaje engañoso ("activa
 * tu acceso") para un problema que no tiene nada que ver con el acceso.
 */
class TrainingCatalogInsufficientException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No eligible main-phase exercises available for this profile/location/equipment.');
    }
}
