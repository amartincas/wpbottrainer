<?php

namespace App\Training\Support;

/**
 * Backstop determinista de detección de señales de alarma en texto libre
 * (Hito 4). Actúa como red de seguridad adicional junto a la clasificación
 * del LLM (Extract) — ninguna de las dos fuentes por sí sola es autoritativa;
 * el bloqueo real lo ejecuta una regla determinista que llama a
 * TrainingProfile::flagForSafetyReview(). El LLM nunca decide por sí mismo
 * que una señal de alarma es segura, y nunca puede desbloquear un perfil.
 *
 * // REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN
 * Esta lista de categorías y patrones, y el mensaje de escalamiento, deben
 * ser validados por personal calificado (negocio + asesoría profesional de
 * salud) antes de operar con usuarios reales. Es deliberadamente
 * conservadora y no exhaustiva — no sustituye criterio médico.
 */
class SafetySignalDetector
{
    private const PATTERNS = [
        'chest_pain' => ['dolor de pecho', 'dolor en el pecho', 'opresión en el pecho'],
        'breathing_difficulty' => ['dificultad para respirar', 'no puedo respirar', 'me ahogo'],
        'loss_of_consciousness' => ['perdí el conocimiento', 'me desmayé', 'me desmayo', 'mareo severo'],
        'recent_surgery' => ['cirugía reciente', 'me operaron', 'recién operado', 'recién operada'],
        'neurological' => ['entumecimiento', 'hormigueo severo', 'pérdida de sensibilidad'],
        'acute_undiagnosed_injury' => ['lesión grave', 'fractura', 'no puedo mover'],
        'pregnancy_complication' => ['embarazo de riesgo', 'complicación en el embarazo'],
    ];

    /**
     * // REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN
     */
    public const ESCALATION_MESSAGE = 'Por tu seguridad, antes de continuar con el entrenamiento '
        .'te recomendamos consultar con un profesional de la salud. Hemos pausado la generación '
        .'automática de tu rutina; un miembro de nuestro equipo revisará tu caso.';

    /**
     * Devuelve la categoría detectada (una clave de PATTERNS) o null si no
     * hay coincidencia. Nunca diagnostica ni interpreta severidad — solo
     * detecta coincidencia textual con frases conocidas.
     */
    public function detect(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        foreach (self::PATTERNS as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, $phrase)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
