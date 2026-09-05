<?php

namespace App\Training\Support;

use App\Factories\AIServiceFactory;
use App\Models\Tenant;
use App\Training\Enums\Equipment;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\Sex;
use App\Training\Enums\TrainingGoal;
use App\Training\Enums\TrainingLocation;
use Illuminate\Support\Facades\Log;

/**
 * El Extract/Narrate de onboarding (Hito 5, fusionados en una sola llamada
 * de IA en el Hito 5.1, ampliado en Hito 8.3) — fuera de TrainingHandler
 * porque construir el prompt y validar el JSON es una responsabilidad real,
 * testeable de forma independiente, no orquestación.
 *
 * Extract → Decide → Narrate (docs/DECISIONS.md, D007, D026, D033):
 * - extractAndRespond() hace Extract Y Narrate en UNA sola llamada.
 * - Decidir qué campo falta de verdad sigue sin pasar por aquí — desde el
 *   Bloque 4 es `App\Training\Onboarding\OnboardingRequirementRegistry`
 *   (determinista) quien decide, TrainingHandler solo pasa la clave.
 * - resolveQuestion() es el punto de Decide para la redacción, sin cambios.
 *
 * Bloque 4 (D047): el JSON/prompt/validadores permanecen intactos — la
 * única adición es `$opportunisticInvitation` en extractAndRespond(), un
 * fragmento ya compuesto por `OnboardingConversationComposer` según la
 * política de turnos progresivos (Opción A: sin segunda llamada de IA).
 *
 * Hito 8.3: se agregan `name` (persistido en Contact.customer_name, no en
 * TrainingProfile — TrainingHandler lo aplica al modelo correcto),
 * `training_location`, `equipment_fully_equipped` (representación explícita
 * de "disponibilidad amplia/completa" sin enumerar — nunca asume que CUALQUIER
 * gimnasio tiene absolutamente todo, solo registra la declaración del
 * usuario), y los 4 datos físicos (`age`/`sex`/`weight_kg`/`height_cm`) —
 * capturados desde MVP pero NUNCA bloqueantes (ver TrainingProfile).
 */
class OnboardingConversationService
{
    private const FALLBACK_QUESTIONS = [
        'name' => '¿Cómo te gustaría que te llame?',
        'goal' => '¿Cuál es tu objetivo principal: perder peso, ganar músculo, mejorar tu condición física general o resistencia?',
        'experience_level' => '¿Cuál es tu nivel de experiencia entrenando: principiante, intermedio o avanzado?',
        'primary_focus' => '¿Hay alguna zona de tu cuerpo que quieras priorizar especialmente? Por ejemplo glúteos, piernas, espalda o abdomen — o si prefieres trabajar todo por igual, también dime.',
        'training_location' => '¿Dónde vas a entrenar: en casa, en el gimnasio, o al aire libre?',
        'restrictions' => '¿Tienes alguna lesión, dolor o limitación física que debamos tener en cuenta?',
        'available_equipment' => '¿Qué equipo tienes disponible para entrenar? Por ejemplo mancuernas, bandas, barra, o ninguno.',
        'sessions_per_week' => '¿Cuántos días a la semana puedes entrenar?',
        'physical_stats' => 'Para terminar de afinar tu plan, si quieres cuéntame tu edad, sexo, peso y estatura — no es obligatorio.',
        // Bloque 5: HealthScreeningRequirement tiene un segundo texto de
        // contingencia (seguimiento) que NO vive aquí — ver
        // resolveQuestion()'s $fallbackOverride y
        // HealthScreeningRequirement::questionContext(). Esta entrada es
        // solo el default genérico (pregunta inicial) para cualquier
        // llamador que no pase el override explícito.
        'health_screening' => 'Antes de comenzar, quiero asegurarme de adaptar bien tu entrenamiento. '
            .'¿Tienes actualmente alguna lesión, dolor, molestia o condición que debamos tener en cuenta?',
    ];

    /**
     * Hito 5.1/8.3/8.4: mapea cada campo pendiente al valor de `next_action`
     * que la IA debe usar cuando ESE es el campo que falta. Código puro,
     * cerrado — nunca lo decide la IA. Único consumidor: resolveQuestion().
     * "complete_onboarding" no aparece aquí a propósito.
     */
    private const NEXT_ACTION_MAP = [
        'name' => 'ask_name',
        'goal' => 'ask_goal',
        'experience_level' => 'ask_experience_level',
        'primary_focus' => 'ask_primary_focus',
        'training_location' => 'ask_training_location',
        'restrictions' => 'ask_restrictions',
        'available_equipment' => 'ask_equipment',
        'sessions_per_week' => 'ask_sessions_per_week',
        'physical_stats' => 'ask_physical_stats',
        'health_screening' => 'ask_health_screening',
    ];

    private const VALID_NEXT_ACTIONS = [
        'ask_name', 'ask_goal', 'ask_experience_level', 'ask_primary_focus', 'ask_training_location',
        'ask_restrictions', 'ask_equipment', 'ask_sessions_per_week', 'ask_physical_stats',
        'ask_health_screening',
        'complete_onboarding',
    ];

    /**
     * Un `response` más largo que esto se descarta igual que uno vacío —
     * probablemente una alucinación/repetición, no una pregunta natural
     * breve de WhatsApp.
     */
    private const MAX_RESPONSE_LENGTH = 300;

    /**
     * Extract + Narrate en una sola llamada.
     *
     * @param  array<string, mixed>|null  $knownProfile  el ContextFragment
     *                                                   `training_profile` actual (campos ya respondidos, incluido el
     *                                                   nombre desde Contact), para que la IA no vuelva a preguntar lo
     *                                                   que ya sabe.
     * @return array{
     *     extracted: array{
     *         name: ?string, goal: ?string, experience_level: ?string,
     *         primary_focus: ?array, secondary_focus: ?array,
     *         training_location: ?string, available_equipment: ?array,
     *         equipment_fully_equipped: ?bool, restrictions: ?array,
     *         sessions_per_week: ?int, age: ?int, sex: ?string,
     *         weight_kg: ?float, height_cm: ?int, safety_signal_text: ?string,
     *         health_declaration_category: ?string, health_condition_text: ?string,
     *         functional_limitation_text: ?string,
     *     },
     *     next_action: ?string,
     *     response: ?string,
     * }
     */
    /**
     * @param  string|null  $pendingField  el campo que TrainingProfile::
     *                                     firstMissingOnboardingField() determinó como pendiente ANTES
     *                                     de este turno (name/goal/experience_level/primary_focus/...).
     *                                     Hito 9.3 (fix post-E2E) — sin esto, la IA no tenía forma de
     *                                     saber a QUÉ pregunta estaba respondiendo el usuario: un
     *                                     mensaje corto y ambiguo ("piernas", "no") se interpretaba sin
     *                                     contexto y a veces no se extraía a ningún campo. $pendingField
     *                                     es una SEÑAL para la extracción, nunca autoridad — el código
     *                                     sigue siendo el único que decide qué falta de verdad
     *                                     (TrainingProfile::firstMissingOnboardingField()), exactamente
     *                                     igual que antes de este fix.
     */
    /**
     * @param  string|null  $opportunisticInvitation  Bloque 4 — fragmento ya
     *         compuesto por `App\Training\Onboarding\OnboardingConversationComposer`
     *         (política de turnos progresivos), a inyectar en el MISMO
     *         prompt combinado — NUNCA dispara una segunda llamada de IA.
     *         `null` cuando la política de turnos no invita a ningún
     *         requirement oportunista este turno (ver
     *         OnboardingRequirementRegistry::secondaryOpportunisticFor()).
     */
    public function extractAndRespond(string $messageBody, ?array $knownProfile, Tenant $tenant, ?string $pendingField = null, ?string $opportunisticInvitation = null): array
    {
        $empty = $this->emptyResult();

        if (trim($messageBody) === '') {
            return $empty;
        }

        try {
            $ai = AIServiceFactory::make($tenant);
            $raw = $ai->getResponse($messageBody, $this->buildCombinedPrompt($knownProfile ?? [], $pendingField, $opportunisticInvitation), []);

            return $this->parseCombinedJson($raw);
        } catch (\Throwable $e) {
            Log::warning('Training onboarding extraction failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Bloque 4 — único punto de verdad para el texto de contingencia de un
     * campo: usado tanto por `resolveQuestion()` (sin cambios) como por
     * cada `OnboardingRequirement::questionContext()->fallbackQuestion`
     * (nuevo), para no duplicar los textos en dos lugares.
     */
    public static function fallbackQuestionFor(string $key): string
    {
        return self::FALLBACK_QUESTIONS[$key] ?? self::FALLBACK_QUESTIONS['goal'];
    }

    /**
     * Decide, de forma 100% determinista, qué pregunta enviar al usuario.
     * $realMissingField viene de la autoridad real (hoy
     * OnboardingRequirementRegistry::firstPendingBlocking()) — nunca de la IA.
     *
     * Bloque 5: `$fallbackOverride` es opcional y retrocompatible — cuando
     * se omite, se preserva exactamente el comportamiento anterior
     * (`FALLBACK_QUESTIONS[$realMissingField]`, un texto fijo por campo).
     * Se necesitó porque `HealthScreeningRequirement` tiene DOS textos de
     * contingencia distintos (pregunta inicial vs. de seguimiento) según su
     * propio estado conversacional — un mapa estático de un-texto-por-campo
     * no alcanza para ese caso. El llamador (`TrainingHandler`) pasa
     * siempre `$requirement->questionContext($profile, $contact)->fallbackQuestion`,
     * haciendo de `QuestionContext` la fuente de verdad real para
     * cualquier requirement, estático o dependiente de estado.
     */
    public function resolveQuestion(string $realMissingField, ?string $aiNextAction, ?string $aiResponse, ?string $fallbackOverride = null): string
    {
        $expectedAction = self::NEXT_ACTION_MAP[$realMissingField] ?? null;

        if ($expectedAction !== null && $aiNextAction === $expectedAction && $this->isUsableResponse($aiResponse)) {
            return trim($aiResponse);
        }

        return $fallbackOverride ?? self::FALLBACK_QUESTIONS[$realMissingField] ?? self::FALLBACK_QUESTIONS['goal'];
    }

    public function usedAiResponse(string $realMissingField, ?string $aiNextAction, ?string $aiResponse): bool
    {
        $expectedAction = self::NEXT_ACTION_MAP[$realMissingField] ?? null;

        return $expectedAction !== null && $aiNextAction === $expectedAction && $this->isUsableResponse($aiResponse);
    }

    /**
     * Hito 9.3 (fix post-E2E). Sin esta línea, la IA extraía el mensaje del
     * usuario sin saber a qué pregunta respondía — un "piernas" o un "no"
     * sueltos, sin ese contexto, a veces no se lograban clasificar en
     * ningún campo. Devuelve cadena vacía si no hay campo pendiente
     * reconocido (nunca bloquea la extracción por esto).
     */
    private function buildPendingFieldContext(?string $pendingField): string
    {
        $label = self::PENDING_FIELD_LABELS[$pendingField] ?? null;

        if ($label === null) {
            return '';
        }

        return "\nLa pregunta que ACABAS de hacerle al usuario, a la que este mensaje probablemente responde, es sobre: {$label}.\n";
    }

    private function isUsableResponse(?string $response): bool
    {
        if ($response === null) {
            return false;
        }

        $trimmed = trim($response);

        return $trimmed !== '' && mb_strlen($trimmed) <= self::MAX_RESPONSE_LENGTH;
    }

    private function emptyResult(): array
    {
        return [
            'extracted' => [
                'name' => null,
                'goal' => null,
                'experience_level' => null,
                'primary_focus' => null,
                'secondary_focus' => null,
                'training_location' => null,
                'available_equipment' => null,
                'equipment_fully_equipped' => null,
                'restrictions' => null,
                'sessions_per_week' => null,
                'age' => null,
                'sex' => null,
                'weight_kg' => null,
                'height_cm' => null,
                'safety_signal_text' => null,
                'health_declaration_category' => null,
                'health_condition_text' => null,
                'functional_limitation_text' => null,
            ],
            'next_action' => null,
            'response' => null,
        ];
    }

    /**
     * Hito 9.3 (fix post-E2E): etiqueta humana de cada campo, SOLO para que
     * la IA sepa a qué pregunta está respondiendo el mensaje actual — nunca
     * se le muestra al usuario (eso lo sigue decidiendo resolveQuestion()/
     * FALLBACK_QUESTIONS). Reutiliza las mismas claves que NEXT_ACTION_MAP.
     */
    private const PENDING_FIELD_LABELS = [
        'name' => 'cómo se llama / cómo quiere que le llamen',
        'goal' => 'su objetivo general de entrenamiento (perder peso, ganar músculo, condición física general, o resistencia)',
        'experience_level' => 'su nivel de experiencia entrenando',
        'primary_focus' => 'si quiere priorizar alguna zona del cuerpo en especial',
        'training_location' => 'dónde va a entrenar',
        'available_equipment' => 'qué equipo tiene disponible',
        'restrictions' => 'si tiene alguna lesión, dolor o limitación física',
        'sessions_per_week' => 'cuántos días a la semana puede entrenar',
        'physical_stats' => 'sus datos físicos (edad/sexo/peso/estatura), opcionales',
        'health_screening' => 'si tiene alguna lesión, dolor, molestia o condición de salud relevante, y si hay algún movimiento específico que deba evitar',
    ];

    private function buildCombinedPrompt(array $knownProfile, ?string $pendingField = null, ?string $opportunisticInvitation = null): string
    {
        $known = json_encode($knownProfile);
        $pendingContext = $this->buildPendingFieldContext($pendingField);
        $opportunisticContext = $opportunisticInvitation ?? '';

        return <<<PROMPT
Eres un entrenador personal cercano, escribiendo por WhatsApp en español, ayudando a un usuario a configurar su perfil de entrenamiento.

Perfil ya conocido (no lo repitas ni lo cambies si ya está aquí, salvo que el usuario lo corrija explícitamente): {$known}
{$pendingContext}{$opportunisticContext}
Del mensaje del usuario, extrae ÚNICAMENTE lo que menciona explícitamente. NUNCA inventes ni asumas un valor que no fue mencionado.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown, sin explicación) con esta forma exacta:
{
  "extracted": {
    "name": "<nombre o como quiere que le llamen>" | null,
    "goal": "lose_weight"|"build_muscle"|"general_fitness"|"endurance"|null,
    "experience_level": "beginner"|"intermediate"|"advanced"|null,
    "primary_focus": ["glutes"|"quads"|"hamstrings"|"calves"|"chest"|"back"|"shoulders"|"biceps"|"triceps"|"abs"|"full_body", ...]|[]|null,
    "secondary_focus": ["glutes"|"quads"|"hamstrings"|"calves"|"chest"|"back"|"shoulders"|"biceps"|"triceps"|"abs"|"full_body", ...]|[]|null,
    "training_location": "home"|"gym"|"outdoor"|null,
    "available_equipment": ["barbell"|"dumbbells"|"kettlebell"|"cable_machine"|"machine"|"resistance_bands"|"bench"|"pull_up_bar"|"medicine_ball"|"mat"|"chair"|"box"|"weighted_vest"|"smith_machine"|"stability_ball"|"wall"|"cone"|"free_weights"|"landmine"|"foam_roller"|"step"|"towel", ...]|[]|null,
    "equipment_fully_equipped": true|false|null,
    "restrictions": ["tag", ...]|[]|null,
    "sessions_per_week": <entero 1-14>|null,
    "age": <entero>|null,
    "sex": "male"|"female"|"prefer_not_to_say"|null,
    "weight_kg": <número>|null,
    "height_cm": <entero>|null,
    "safety_signal_text": "<frase textual>"|null,
    "health_declaration_category": "possible_injury"|"possible_recovery"|"professional_indication"|null,
    "health_condition_text": "<frase textual>"|""|null,
    "functional_limitation_text": "<frase textual>"|null
  },
  "next_action": "ask_name"|"ask_goal"|"ask_experience_level"|"ask_primary_focus"|"ask_training_location"|"ask_restrictions"|"ask_equipment"|"ask_sessions_per_week"|"ask_physical_stats"|"ask_health_screening"|"complete_onboarding",
  "response": "<tu respuesta conversacional en español>"
}

Reglas de "extracted":
- Usa null en cualquier campo que el mensaje no mencione. Usa [] únicamente si el usuario dice explícitamente que no tiene restricciones, no tiene equipo, o no tiene ninguna zona que priorizar (quiere trabajar todo por igual).
- Cualquier lesión, dolor, molestia o limitación física que el usuario mencione (ej. "dolor en la rodilla", "molestia en la espalda") va SIEMPRE en "restrictions", sin importar si también aparece en "safety_signal_text" — son campos independientes, pueden llenarse ambos a la vez o solo uno.
- "safety_signal_text": SOLO llénalo si el mensaje sugiere una posible urgencia médica real (dolor de pecho, dificultad para respirar, pérdida de conocimiento/desmayo, cirugía muy reciente, entumecimiento/hormigueo severo, lesión grave repentina, o complicación de embarazo). Una molestia o dolor ordinario de entrenamiento (rodilla, espalda, hombro, ciática, etc., sin esos signos) NO es una urgencia — usa null aquí aunque sí llenes "restrictions". Ejemplo: "tengo dolor en las rodillas" → restrictions: ["dolor en las rodillas"], safety_signal_text: null.
- "health_condition_text"/"health_declaration_category"/"functional_limitation_text" son ESPECÍFICOS de la pregunta de screening de salud (independientes de "restrictions", que sigue existiendo por compatibilidad con perfiles antiguos pero ya no se usa para decidir nada nuevo) — solo tienen sentido cuando la pregunta pendiente indicada arriba es sobre "detectar cualquier lesión, dolor, molestia o condición" o sobre "identificar si existe un movimiento específico que deba evitarse":
  - "health_condition_text": si el usuario NIEGA explícitamente tener cualquier lesión/dolor/molestia/condición (de cualquier forma natural: "no", "ninguna", "no tengo nada", "estoy bien"), usa "" (cadena vacía) — NUNCA null en ese caso (null significa "el mensaje no abordó el tema todavía"). Si el usuario SÍ menciona algo, usa el texto LITERAL de lo que dijo sobre su condición (ej. "tengo una lesión de hombro", o incluso algo vago como "me duele" o "sí, me molesta") — nunca lo resumas, nunca lo completes con detalles que no dijo, nunca lo dejes vacío si hay contenido real. Una afirmación totalmente vacía de contenido (un "sí" suelto, sin decir qué le pasa, cuando no es claro si entendió la pregunta) déjala en null en vez de inventar un texto — es preferible seguir preguntando a fabricar una declaración.
  - "health_declaration_category": clasifica el texto de "health_condition_text" (cuando no es "" ni null) en "possible_injury" (lesión/dolor/molestia propia, el caso por defecto), "possible_recovery" (el usuario dice que ya se recuperó, ya está bien, ya sanó de algo que tenía antes), o "professional_indication" (el usuario reporta que un profesional de la salud —médico, fisioterapeuta, etc.— le dio una indicación). Si no es evidente cuál aplica, usa "possible_injury".
  - "functional_limitation_text": SOLO cuando el usuario describe EXPLÍCITAMENTE qué movimiento, ejercicio o acción física no puede realizar o debe evitar (ej. "no puedo levantar el brazo por encima de la cabeza", "no puedo hacer sentadillas profundas", "debo evitar cargar peso en la espalda") — NUNCA lo infieras ni lo generes a partir de solo mencionar una lesión o dolor sin ese detalle. Si el usuario solo dice "tengo una lesión de hombro" sin especificar qué movimiento evitar, deja este campo en null aunque sí llenes "health_condition_text". Preserva el texto LITERAL — nunca resumas ni traduzcas esto a una zona del cuerpo; esa traducción la hace el código, nunca tú.
- "equipment_fully_equipped": true SOLO si el usuario indica acceso amplio o completo a equipo SIN enumerar (ej. "tengo de todo", "tengo todo", "lo normal de un gimnasio", "está bien equipado") — en ese caso "available_equipment" puede quedar null o vacío, NUNCA inventes una lista de aparatos. Si el usuario menciona equipo específico (ej. "solo pesas", "tengo mancuernas y bandas", o incluso solo dice "gimnasio" sin más detalle sobre qué tiene), usa "available_equipment" con lo mencionado (o null si solo dijo el lugar, sin hablar de equipo) y deja "equipment_fully_equipped" en null — decir dónde entrena no es lo mismo que declarar que tiene todo el equipo.
- "available_equipment": traduce cada aparato mencionado a este vocabulario cerrado (igual criterio que "primary_focus" — nunca dejes el término en español libre, nunca inventes un valor fuera de esta lista), usando esta tabla:
  - "mancuernas"/"pesas" (sin más detalle)/"pesas de mano" → "dumbbells"
  - "barra"/"barra olímpica" → "barbell"
  - "máquinas"/"máquina"/"aparatos" (sin más detalle) → "machine"
  - "máquina smith"/"smith machine" → "smith_machine"
  - "polea"/"máquina de cable"/"cable" → "cable_machine"
  - "pesa rusa"/"kettlebell" → "kettlebell"
  - "bandas elásticas"/"bandas de resistencia"/"ligas" → "resistance_bands"
  - "banco" → "bench"
  - "barra de dominadas"/"barra para dominadas" → "pull_up_bar"
  - "balón medicinal"/"pelota medicinal" → "medicine_ball"
  - "colchoneta"/"tapete"/"mat" → "mat"
  - "silla" → "chair"
  - "cajón"/"caja pliométrica"/"step box" → "box"
  - "chaleco con peso"/"chaleco lastrado" → "weighted_vest"
  - "balón suizo"/"pelota de estabilidad"/"fitball" → "stability_ball"
  - "pared" → "wall"
  - "cono"/"conos" → "cone"
  - "pesas libres" (sin especificar mancuernas/barra) → "free_weights"
  - "landmine"/"anclaje de barra" → "landmine"
  - "rodillo de espuma"/"foam roller" → "foam_roller"
  - "escalón"/"step" → "step"
  - "toalla" → "towel"
  Si el usuario menciona un aparato que no calza claramente con ninguno de estos (y no es una declaración de "todo"/"nada"), omítelo del arreglo en vez de forzar una traducción incorrecta — es preferible que falte a que sea errónea.
- "primary_focus": la(s) zona(s) que el usuario indica querer PRIORIZAR especialmente (no es lo mismo que un simple "quiero ponerme en forma", eso es "goal"). Traduce el lenguaje natural a este vocabulario cerrado, usando esta tabla:
  - "glúteos"/"cola"/"pompis" → ["glutes"]
  - "piernas" (sin especificar más) → ["quads", "hamstrings", "glutes", "calves"]
  - "cuádriceps"/"muslos" → ["quads"]
  - "isquiotibiales"/"femorales" → ["hamstrings"]
  - "pantorrillas"/"gemelos" → ["calves"]
  - "espalda" → ["back"]
  - "abdomen"/"abdominales"/"core"/"panza" → ["abs"]
  - "pecho"/"pectorales" → ["chest"]
  - "brazos" (sin especificar más) → ["biceps", "triceps"]
  - "bíceps" → ["biceps"]
  - "tríceps" → ["triceps"]
  - "hombros" → ["shoulders"]
  - "todo por igual"/"todo el cuerpo"/"no tengo preferencia"/"parejo" → [] (respuesta válida, NUNCA null en este caso)
  Si el usuario menciona una zona con MENOR énfasis o de forma secundaria junto a la principal (ej. "sobre todo glúteos, y algo de espalda también"), la principal va en "primary_focus" y la secundaria en "secondary_focus" — NUNCA reveles ni menciones estos dos términos técnicos al usuario, son solo para uso interno.
- Puedes extraer VARIOS campos de un mismo mensaje si el usuario los menciona juntos (ej. "Me llamo Ana, entreno en un gimnasio y tengo de todo" → name, training_location y equipment_fully_equipped los tres a la vez).
- Los datos físicos (age/sex/weight_kg/height_cm) son opcionales para el usuario — si responde "prefiero no decir" o cambia de tema, dejas esos campos en null, nunca insistas ni los inventes.
- Si la pregunta pendiente indicada arriba es sobre el OBJETIVO GENERAL ("goal") y el usuario responde mencionando una o más zonas del cuerpo a priorizar (una respuesta de tipo "foco", ej. "piernas", "quiero trabajar glúteos") SIN mencionar ninguno de los 4 objetivos generales de la lista cerrada, extrae esas zonas en "primary_focus"/"secondary_focus" según corresponda (usando la tabla de traducción de más abajo) y deja "goal" en null — el objetivo general sigue sin responderse, nunca lo inventes ni lo fuerces a partir de una respuesta de foco. Esto aplica de forma general a cualquier zona del cuerpo, no solo a los ejemplos mencionados aquí.
- Si la pregunta pendiente indicada arriba es sobre RESTRICCIONES/lesiones ("restrictions") y el usuario responde con cualquier negación natural (de cualquier forma: "no", "no tengo", "ninguna", "ninguno", "nada", "no la verdad", o equivalente), SIN mencionar ninguna lesión o limitación real, interpreta esto como una respuesta explícita de "sin restricciones" y usa restrictions: [] — nunca lo dejes en null en este caso (null significa "todavía no respondió", no "respondió que no tiene ninguna"). Esta regla es sobre el PATRÓN semántico de una negación directa a esa pregunta, no una lista fija de frases — reconoce cualquier forma natural equivalente en español.

Reglas de "next_action": indica cuál de estos campos pendientes sigue sin responderse, en este orden de prioridad: name, goal, experience_level, training_location, available_equipment, health_screening, y luego (opcionales, no bloqueantes) sessions_per_week, primary_focus, datos físicos. Usa "complete_onboarding" solo si ya no falta nada de lo anterior. Este valor es solo orientativo — el sistema siempre verifica el estado real antes de usarlo.

Reglas de "response": redacta en tono natural y cercano, como un entrenador personal real — NUNCA como un formulario. Si ya conoces el nombre del usuario, puedes usarlo con naturalidad. Si el onboarding sigue incompleto, reconoce brevemente lo que el usuario acaba de decir y luego haz la siguiente pregunta de forma conversacional. Máximo 2-3 frases. Nunca uses los términos técnicos "primary_focus"/"secondary_focus" — habla de "zona a priorizar" o similar, en lenguaje natural.
PROMPT;
    }

    /**
     * El resultado de la IA nunca se confía tal cual: cada campo de
     * "extracted" se valida contra el vocabulario/forma permitida aquí, y
     * cualquier valor inválido se descarta (queda null) en vez de
     * persistirse.
     */
    private function parseCombinedJson(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return $this->emptyResult();
        }

        $extractedRaw = is_array($decoded['extracted'] ?? null) ? $decoded['extracted'] : [];
        $nextAction = $decoded['next_action'] ?? null;

        return [
            'extracted' => [
                'name' => $this->validateName($extractedRaw['name'] ?? null),
                'goal' => $this->validateEnumValue($extractedRaw['goal'] ?? null, TrainingGoal::class),
                'experience_level' => $this->validateEnumValue($extractedRaw['experience_level'] ?? null, ExperienceLevel::class),
                'primary_focus' => $this->validateMuscleFocusArray($extractedRaw['primary_focus'] ?? null),
                'secondary_focus' => $this->validateMuscleFocusArray($extractedRaw['secondary_focus'] ?? null),
                'training_location' => $this->validateEnumValue($extractedRaw['training_location'] ?? null, TrainingLocation::class),
                'available_equipment' => $this->validateEquipmentArray($extractedRaw['available_equipment'] ?? null),
                'equipment_fully_equipped' => $this->validateBool($extractedRaw['equipment_fully_equipped'] ?? null),
                'restrictions' => $this->validateStringArray($extractedRaw['restrictions'] ?? null),
                'sessions_per_week' => $this->validateSessionsPerWeek($extractedRaw['sessions_per_week'] ?? null),
                'age' => $this->validateIntRange($extractedRaw['age'] ?? null, 10, 100),
                'sex' => $this->validateEnumValue($extractedRaw['sex'] ?? null, Sex::class),
                'weight_kg' => $this->validateNumericRange($extractedRaw['weight_kg'] ?? null, 20, 300),
                'height_cm' => $this->validateIntRange($extractedRaw['height_cm'] ?? null, 100, 250),
                'safety_signal_text' => is_string($extractedRaw['safety_signal_text'] ?? null) && $extractedRaw['safety_signal_text'] !== ''
                    ? $extractedRaw['safety_signal_text']
                    : null,
                'health_declaration_category' => $this->validateEnumValue($extractedRaw['health_declaration_category'] ?? null, HealthConditionCategory::class),
                'health_condition_text' => $this->validateHealthConditionText($extractedRaw['health_condition_text'] ?? null),
                'functional_limitation_text' => $this->validateNonEmptyString($extractedRaw['functional_limitation_text'] ?? null),
            ],
            'next_action' => is_string($nextAction) && in_array($nextAction, self::VALID_NEXT_ACTIONS, true) ? $nextAction : null,
            'response' => is_string($decoded['response'] ?? null) ? $decoded['response'] : null,
        ];
    }

    private function validateName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && mb_strlen($trimmed) <= 60) ? $trimmed : null;
    }

    private function validateBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Bloque 5 — a diferencia de validateNonEmptyString(), preserva la
     * distinción "" (negación explícita: preguntado, ninguna condición) vs.
     * null (todavía sin responder) — mismo criterio ya usado para
     * restrictions/available_equipment/primary_focus con arrays vacíos.
     */
    private function validateHealthConditionText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && mb_strlen($trimmed) <= self::MAX_RESPONSE_LENGTH) ? $trimmed : null;
    }

    /**
     * Bloque 5 — para functional_limitation_text: SOLO texto literal no
     * vacío, o null. A diferencia de health_condition_text, aquí "" no
     * tiene significado propio (no existe una "negación explícita de
     * limitación funcional" distinta de simplemente no mencionarla).
     */
    private function validateNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && mb_strlen($trimmed) <= self::MAX_RESPONSE_LENGTH) ? $trimmed : null;
    }

    private function validateEnumValue(mixed $value, string $enumClass): ?string
    {
        return is_string($value) ? $enumClass::tryFrom($value)?->value : null;
    }

    private function validateStringArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * A diferencia de validateStringArray(), restringe cada elemento al
     * vocabulario cerrado de MuscleFocus — cualquier valor que la IA
     * hubiera inventado fuera de esta lista se descarta silenciosamente
     * (nunca se persiste una zona muscular que el código no reconoce).
     */
    private function validateMuscleFocusArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? MuscleFocus::tryFrom($v)?->value : null, $value)
        )));
    }

    /**
     * Hito 9.3 (post-deploy, corrección) — hallazgo real: `available_equipment`
     * se guardaba como texto libre (lo que la IA escribiera, en el idioma
     * que fuera), mientras `Exercise.equipment_needed` siempre usó el
     * vocabulario cerrado `App\Training\Enums\Equipment` (vía cada
     * ExerciseNormalizerInterface). `TrainingEngine::isEligible()` los
     * comparaba directamente — "máquinas" nunca podía calzar con
     * "machine". Mismo criterio que validateMuscleFocusArray(): cualquier
     * valor que la IA hubiera devuelto fuera de este vocabulario se
     * descarta silenciosamente, nunca se persiste equipo que el código no
     * reconoce.
     */
    private function validateEquipmentArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? Equipment::tryFrom($v)?->value : null, $value)
        )));
    }

    private function validateSessionsPerWeek(mixed $value): ?int
    {
        return $this->validateIntRange($value, 1, 14);
    }

    private function validateIntRange(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
        } else {
            return null;
        }

        return ($int >= $min && $int <= $max) ? $int : null;
    }

    private function validateNumericRange(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return ($float >= $min && $float <= $max) ? $float : null;
    }
}
