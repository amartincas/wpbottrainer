<?php

namespace App\Core\Messaging;

/**
 * Interfaz marcadora (vacía, sin métodos propios) para un IntentClassifier
 * cuya clasificación puede depender ÚNICAMENTE del estado/contexto del
 * Contact, sin ninguna señal textual explícita en el mensaje (ej.
 * TrainingContextualIntentClassifier::hasPendingWorkoutSession()).
 *
 * Su único propósito es permitir que un test de arquitectura verifique,
 * para CUALQUIER classifier presente o futuro, que ninguno marcado como
 * "contextual" pueda registrarse en un tier de Router anterior al último —
 * sin depender de mantener una lista de nombres de clase a mano. Ver
 * docs/DECISIONS.md (precedencia de Intents).
 *
 * Un classifier 100% explícito (basado solo en texto del mensaje) NUNCA
 * debe implementar esta interfaz.
 */
interface ContextualIntentClassifierInterface extends IntentClassifierInterface {}
