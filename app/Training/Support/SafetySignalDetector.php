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
     * Hito 7 (hallazgo de la prueba E2E real): las frases exactas de arriba
     * no reconocían "me duele mucho el pecho" — una paráfrasis obvia de
     * "dolor de pecho" que un usuario real efectivamente escribió. Coincidir
     * substring exacto es demasiado frágil para esta categoría en concreto.
     *
     * Regla de co-ocurrencia (todavía determinista, todavía una lista
     * cerrada y curada — NO un modelo de lenguaje ni NLP general): dispara
     * si el texto contiene alguna palabra de "triggers" Y alguna de
     * "anchors", sin importar el orden o la conjugación exacta. Cubre
     * "dolor de pecho", "me duele el pecho", "me duele mucho el pecho",
     * "siento presión en el pecho", "molestia en el pecho", etc.
     *
     * Solo se generaliza chest_pain aquí — es la categoría con el fallo
     * demostrado en la prueba real. Las demás categorías conservan sus
     * frases exactas sin cambios (cambio acotado, no se reescribe todo el
     * detector sin un caso de falla concreto para cada una).
     */
    private const CO_OCCURRENCE_PATTERNS = [
        'chest_pain' => [
            'triggers' => ['dolor', 'duele', 'duelen', 'molestia', 'opresión', 'opresion', 'presión', 'presion', 'punzada'],
            'anchors' => ['pecho'],
        ],
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

        foreach (self::CO_OCCURRENCE_PATTERNS as $category => $rule) {
            if ($this->containsAny($normalized, $rule['triggers']) && $this->containsAny($normalized, $rule['anchors'])) {
                return $category;
            }
        }

        return null;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
