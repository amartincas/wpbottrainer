<?php

namespace App\Training\Enums;

/**
 * Hito de seguridad de restricciones. Único valor activo hoy — el enum
 * existe para que agregar un segundo comportamiento (ej. reducir
 * dificultad en vez de excluir) no requiera migrar filas existentes.
 */
enum RestrictionType: string
{
    case ExcludeExercise = 'exclude_exercise';
}
