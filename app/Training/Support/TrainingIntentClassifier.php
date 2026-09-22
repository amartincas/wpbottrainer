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
 *
 * Hito B1.3.1 — corrige un hallazgo real del Code Review de B1.3: un
 * usuario RECURRENTE (ya con historial de WorkoutSession, por lo que
 * `TrainingContextualIntentClassifier::hasActiveAccessAwaitingFirstWorkout()`
 * ya no lo rescata) que escribe SOLO "Quiero trabajar espalda" — sin
 * ninguna de las KEYWORDS de arriba — nunca llegaba a `TrainingHandler`.
 * `containsFocusRequest()` cierra ese hueco sin agregar nombres de
 * músculos a KEYWORDS (eso produciría falsos positivos reales: "me duele
 * la espalda", "¿qué músculos tiene la espalda?", "mi hermano entrena
 * piernas" NO son peticiones de entrenar) — exige la CO-OCURRENCIA de (a)
 * lenguaje EXPLÍCITO de petición ("quiero trabajar/entrenar/hacer...", "me
 * toca...", "vamos con...") y (b) un término de foco reconocido por el
 * MISMO vocabulario cerrado que ya usa `RequestedFocusTermMapper`
 * (`containsRecognizedTerm()`) — nunca una segunda lista de músculos
 * duplicada aquí. Mismo patrón de co-ocurrencia (trigger + ancla, sin NLP,
 * sin LLM) ya usado por `SafetySignalDetector::CO_OCCURRENCE_PATTERNS`
 * para un problema estructuralmente idéntico.
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

    /**
     * Hito B1.3.1 — deliberadamente acotado a lenguaje de PETICIÓN en
     * primera persona ("quiero...", "me toca...", "vamos con...") — nunca
     * verbos sueltos como "entrena"/"duele"/"molesta"/"lesion-" que
     * aparecerían igual en una mención de dolor, una pregunta informativa,
     * o una mención en tercera persona (ver casos negativos del Code
     * Review). La co-ocurrencia con un término reconocido
     * (`containsFocusRequest()`) es la que hace la señal segura — ninguna
     * de las dos partes basta por sí sola.
     */
    private const FOCUS_REQUEST_PHRASES = [
        'quiero trabajar', 'quiero entrenar', 'quiero hacer', 'hoy quiero',
        'quiero enfocarme en', 'me toca', 'vamos con',
    ];

    /**
     * Hito B1.3.1.1 (corrección de CRITICAL-1 del Code Review) — NO es una
     * lista de seguridad. `SafetySignalDetector` sigue siendo la ÚNICA
     * autoridad de Safety, sin cambios, ejecutándose sin condición sobre
     * TODO mensaje que llegue a `TrainingHandler` por cualquier vía. Esta
     * lista es una condición de EXCLUSIÓN CONSERVADORA, exclusiva de
     * `containsFocusRequest()`: si el mensaje que dispara un
     * `FOCUS_REQUEST_PHRASES` también contiene alguno de estos marcadores,
     * la regla nueva simplemente se ABSTIENE de reclamar `Intent::Training`
     * — nunca decide que el mensaje es seguro, nunca escala, nunca
     * reemplaza a `SafetySignalDetector`. Deliberadamente pequeña (nunca
     * "extensa") — cierra el hallazgo real del Code Review:
     * "Quiero trabajar espalda pero me duele"/"...piernas porque me
     * lesioné" ya no reclaman Training por esta vía. Ambas variantes
     * con/sin tilde donde aplica, mismo criterio que
     * `SafetySignalDetector` con "opresión"/"opresion".
     *
     * NO afecta el flujo de `KEYWORDS` legacy: "Quiero entrenar pecho,
     * aunque me duele" sigue clasificando como Training vía la keyword
     * preexistente "quiero entrenar" — `classify()` retorna en ese bucle
     * ANTES de llegar a `containsFocusRequest()`, así que esta exclusión
     * nunca se evalúa para ese mensaje. Ese caso es un riesgo preexistente
     * de `SafetySignalDetector`, documentado como fuera del alcance de
     * este hito (ver diseño aprobado B1.3.1.1).
     */
    private const CONSERVATIVE_EXCLUSION_MARKERS = [
        'duele', 'duelen', 'dolor', 'molesta', 'molestia',
        'lesion', 'lesión', 'lastim',
    ];

    public function __construct(
        private readonly RequestedFocusTermMapper $requestedFocusTermMapper = new RequestedFocusTermMapper,
    ) {}

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = mb_strtolower($context->message->messageBody ?? '');

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($body, $keyword)) {
                return Intent::Training;
            }
        }

        if ($this->containsFocusRequest($body)) {
            return Intent::Training;
        }

        return null;
    }

    /**
     * Hito B1.3.1 — ver docblock de la clase y de `FOCUS_REQUEST_PHRASES`.
     * `$body` ya llega en minúsculas (ver `classify()`) — `RequestedFocusTermMapper::containsRecognizedTerm()`
     * normaliza igual por su cuenta, así que es seguro invocarlo también de
     * forma independiente en el futuro sin depender de este orden.
     */
    private function containsFocusRequest(string $body): bool
    {
        foreach (self::FOCUS_REQUEST_PHRASES as $phrase) {
            if (str_contains($body, $phrase)) {
                if ($this->containsConservativeExclusionMarker($body)) {
                    return false;
                }

                return $this->requestedFocusTermMapper->containsRecognizedTerm($body);
            }
        }

        return false;
    }

    /**
     * Hito B1.3.1.1 — ver docblock de `CONSERVATIVE_EXCLUSION_MARKERS`.
     */
    private function containsConservativeExclusionMarker(string $body): bool
    {
        foreach (self::CONSERVATIVE_EXCLUSION_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }
}
