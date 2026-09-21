<?php

namespace App\Training\Support;

/**
 * Hito R1/R2/R3 — reconoce, de forma 100% determinista y sin IA, si un
 * mensaje es una confirmación explícita e inequívoca del usuario para
 * avanzar más allá de un ejercicio de preparación/cooldown (que no piden
 * reporte estructurado — ver `WorkoutExercise::requiresExecutionReport()`).
 *
 * Deliberadamente NO reutiliza
 * `TrainingHandler::isShortAffirmativeAfterReminder()`: ese método tiene un
 * efecto secundario real (`Reminder::update(['awaiting_response_until' =>
 * null])`) atado semánticamente a `Reminder`, y su vocabulario
 * (`dale`/`empecemos`/`va`/`bueno`) ni coincide con el de este detector ni
 * excluye lo que este SÍ necesita (`hecho`/`ya`/`terminé`/`continuar`/
 * `siguiente`) — reutilizarlo habría sido forzar una semántica que no
 * corresponde. Esta clase reutiliza únicamente el MISMO IDIOMA de
 * normalización ya establecido en ese método (trim + minúsculas + quitar
 * puntuación), con su propio vocabulario dedicado, sin tocar ningún modelo.
 *
 * Coincidencia EXACTA sobre el mensaje completo normalizado — nunca una
 * búsqueda de subcadena — para que preguntas reales ("¿cuánto dura?",
 * "¿puedo cambiarlo?") nunca se confundan con una confirmación. Mismo
 * criterio ya usado por `TrainingHandler::SHORT_AFFIRMATIVE_WORDS`: el
 * proyecto no normaliza acentos programáticamente en ningún punto
 * existente — enumera explícitamente ambas variantes cuando aplica (ej.
 * "sí"/"si", "terminé"/"termine"), y este detector sigue exactamente ese
 * mismo patrón en vez de introducir uno nuevo.
 */
class SupportPhaseConfirmationDetector
{
    private const CONFIRMATION_PHRASES = [
        'sí', 'si', 'listo', 'hecho', 'ok', 'okay', 'ya', 'terminé', 'termine',
        'continuar', 'siguiente', 'vamos', 'dale', 'sigamos', 'seguimos',
        'sigue', 'sigue adelante', 'vamos con el siguiente', 'ya está', 'ya esta',
        'he terminado', 'lo hice', 'lo hice ya',
    ];

    public function isExplicitConfirmation(string $body): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/[.!¡¿?,;]/u', '', $body) ?? $body));

        return in_array($normalized, self::CONFIRMATION_PHRASES, true);
    }
}
