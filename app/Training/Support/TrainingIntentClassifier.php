<?php

namespace App\Training\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;

/**
 * Deterministic classification of "does this message belong to Training?" —
 * no LLM call (Hito 5). A keyword match against the message text is cheap,
 * always-on, and correct enough for the first two intents that exist.
 * LLM-assisted classification (for phrasings the keyword list misses) is a
 * documented future enhancement, not built here — see docs/DECISIONS.md.
 *
 * Precedencia de Intents (ver docs/DECISIONS.md) — este classifier es
 * EXCLUSIVAMENTE explícito: solo reconoce keywords en el texto del mensaje.
 * La clasificación puramente contextual (un Contact mid-onboarding, con una
 * WorkoutSession pendiente, con acceso activo esperando su primer
 * entrenamiento, o esperando respuesta a un Reminder) se extrajo a
 * TrainingContextualIntentClassifier — un classifier separado, registrado en
 * el último tier del Router, para que una señal explícita de OTRO dominio
 * (Referral, Payment, CustomerCare) nunca pierda frente a este contexto.
 *
 * Works on already-transcribed text: Ingest (Core) transcribes audio into
 * IngestedMessage->messageBody before Router ever runs, so this classifier
 * needs no audio-specific handling of its own.
 */
class TrainingIntentClassifier implements IntentClassifierInterface
{
    private const KEYWORDS = [
        'entrenar', 'entrenamiento', 'entreno', 'ejercicio', 'ejercitarme',
        'ejercitar', 'rutina', 'gimnasio', 'gym', 'ponerme en forma',
        'plan de entrenamiento', 'bajar de peso', 'perder peso',
        'ganar musculo', 'ganar músculo', 'tonificar', 'quiero entrenar',
        'hacer ejercicio', 'ponerme fit', 'estar en forma',
    ];

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = mb_strtolower($context->message->messageBody ?? '');

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($body, $keyword)) {
                return Intent::Training;
            }
        }

        return null;
    }
}
