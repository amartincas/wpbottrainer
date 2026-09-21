<?php

namespace App\ExerciseCatalog\Providers\YMove;

use App\ExerciseCatalog\Contracts\ExerciseNormalizerInterface;
use App\ExerciseCatalog\DTOs\NormalizedExerciseData;
use App\ExerciseCatalog\DTOs\ProviderExerciseData;
use App\Training\Enums\Equipment;
use App\Training\Enums\ExerciseType;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Support\Facades\Log;

/**
 * Hito 9.1 — traduce el shape crudo de YMove al vocabulario cerrado de
 * WpbotTrainer. Único lugar que sabe que YMove llama "muscleGroup" a lo
 * que nosotros llamamos `primary_muscle`, o que su equipo es un string
 * único en vez de un array.
 *
 * Auditoría real (prueba técnica Hito 8.4/9) de los campos que YMove
 * devuelve: id, title, slug, description, instructions[], importantPoints[],
 * muscleGroup, secondaryMuscles[]|null, equipment (string), category,
 * difficulty|null, videoDurationSecs|null, hasVideo*, exerciseType[],
 * videoUrl, videoHlsUrl, thumbnailUrl, thumbnails{}, videos[].
 *
 * YMove NO devuelve `contraindications` en absoluto — se deja
 * deliberadamente en null (nunca inventado), lo que además bloquea
 * `Exercise::activate()` hasta revisión humana real (ver docs/DECISIONS.md).
 * YMove tampoco devuelve un `movement_pattern` explícito — se deja en
 * null en vez de adivinar (mismo criterio, sin heurística confiable).
 */
class YMoveExerciseNormalizer implements ExerciseNormalizerInterface
{
    private const MUSCLE_GROUP_MAP = [
        'glutes' => MuscleFocus::Glutes,
        'quads' => MuscleFocus::Quads,
        'hamstrings' => MuscleFocus::Hamstrings,
        'calves' => MuscleFocus::Calves,
        'chest' => MuscleFocus::Chest,
        'back' => MuscleFocus::Back,
        'shoulders' => MuscleFocus::Shoulders,
        'biceps' => MuscleFocus::Biceps,
        'triceps' => MuscleFocus::Triceps,
        'abs' => MuscleFocus::Abs,
        'full_body' => MuscleFocus::FullBody,

        // Hito Provider-Agnostic Normalization (Audit #4) — 14 aliases
        // verificados contra ejemplos reales del catálogo vivo: cada uno
        // es un sub-músculo/variante de formato de un MuscleFocus YA
        // existente, con pérdida de granularidad aceptada explícitamente
        // (ej. "lats" es parte de la espalda, no un foco nuevo). Ningún
        // caso nuevo se agregó a MuscleFocus para esto — el dominio no
        // adopta automáticamente la granularidad del proveedor.
        'quadriceps' => MuscleFocus::Quads,
        'full body' => MuscleFocus::FullBody, // variante de formato (espacio) de "full_body"
        'lats' => MuscleFocus::Back,
        'erector_spinae' => MuscleFocus::Back,
        'lower_back' => MuscleFocus::Back,
        'glute_med' => MuscleFocus::Glutes,
        'lower_abs' => MuscleFocus::Abs,
        'obliques' => MuscleFocus::Abs,
        'rectus_abdominis' => MuscleFocus::Abs,
        'upper_chest' => MuscleFocus::Chest,
        'lower_chest' => MuscleFocus::Chest,
        'front_deltoids' => MuscleFocus::Shoulders,
        'lateral_deltoids' => MuscleFocus::Shoulders,
        'rear_deltoids' => MuscleFocus::Shoulders,
    ];

    /**
     * Hito Provider-Agnostic Normalization (Audit #4) — valores que YMove
     * devuelve en el campo `muscleGroup` pero que NO son un músculo:
     * duplican un concepto de OTRO campo (equipment: "bodyweight"/
     * "ketllebell"[sic]/"kettlebell-exercises"/"smith-machine") o son una
     * categoría de sistema/tipo, no de foco muscular ("cardio"/
     * "cardiovascular_system" — más cercano a `exercise_type`). Nunca se
     * fuerzan a un `MuscleFocus` — se tratan y loguean como dato
     * INVÁLIDO del proveedor, explícitamente distinto de un valor
     * simplemente no soportado todavía (ver mapMuscleGroup()).
     *
     * @var string[]
     */
    private const INVALID_MUSCLE_GROUP_VALUES = [
        'bodyweight',
        'ketllebell',
        'kettlebell-exercises',
        'smith-machine',
        'cardio',
        'cardiovascular_system',
    ];

    /**
     * El `muscle_group` GRUESO ya existente (Exercise.muscle_group, 6
     * valores, base de la rotación por continuidad de TrainingEngine —
     * sin cambios desde Hito 8.4) se deriva del mismo `muscleGroup` fino
     * de YMove, con un mapeo SEPARADO — mismo criterio dual ya establecido.
     */
    private const MUSCLE_GROUP_COARSE_MAP = [
        'glutes' => 'legs', 'quads' => 'legs', 'hamstrings' => 'legs', 'calves' => 'legs',
        'biceps' => 'arms', 'triceps' => 'arms',
        'abs' => 'core',
        'chest' => 'chest', 'back' => 'back', 'shoulders' => 'shoulders',
    ];

    /**
     * Hito 9.3 (post-deploy, corrección) — completo contra el vocabulario
     * OFICIAL y exhaustivo de YMove (`GET /exercises/equipment`, endpoint
     * de metadata sin costo de cuota, 22 valores reales confirmados en
     * vivo). Antes de esta corrección solo cubría 11 — cualquier ejercicio
     * con uno de los otros 11 caía silenciosamente en `equipment_needed=[]`
     * ("sin equipo") sin que nada lo señalara. `pull-up bar` de YMove usa
     * guion, se preserva tal cual apareció en la auditoría original.
     *
     * Hito 15.2 — `'bodyweight' => []` es un caso especial: a diferencia de
     * cualquier otra clave de este mapa (final, sin más transformación), es
     * la única que puede refinarse más (ver `refineBodyweightEquipment()`)
     * antes de resolverse a `[]`.
     *
     * Hito Provider-Agnostic Normalization — 10 claves más (ver el bloque
     * al final de este array) para 6 objetos de equipo genuinamente
     * nuevos en el dominio + 3 alias con pérdida aceptada + 1 tratado como
     * "sin equipo" — ver docs/DECISIONS.md, Audit #4. Cualquier clave que
     * SIGA sin existir aquí (ej. 'ab wheel', deliberadamente) cae en el
     * fallback de `mapEquipment()`: `Equipment::Unsupported`, nunca `[]`.
     *
     * @var array<string, Equipment[]>
     */
    private const EQUIPMENT_MAP = [
        'bodyweight' => [],
        'barbell' => [Equipment::Barbell],
        'dumbbell' => [Equipment::Dumbbells],
        'dumbbells' => [Equipment::Dumbbells],
        'kettlebell' => [Equipment::Kettlebell],
        'cable' => [Equipment::CableMachine],
        'machine' => [Equipment::Machine],
        'band' => [Equipment::ResistanceBands],
        'bands' => [Equipment::ResistanceBands],
        'bench' => [Equipment::Bench],
        'pull-up bar' => [Equipment::PullUpBar],
        'medicine ball' => [Equipment::MedicineBall],
        'mat' => [Equipment::Mat],
        'chair' => [Equipment::Chair],
        'box' => [Equipment::Box],
        'weighted vest' => [Equipment::WeightedVest],
        'smith machine' => [Equipment::SmithMachine],
        'stability ball' => [Equipment::StabilityBall],
        'wall' => [Equipment::Wall],
        'cone' => [Equipment::Cone],
        'free weights' => [Equipment::FreeWeights],
        'landmine' => [Equipment::Landmine],
        'foam roller' => [Equipment::FoamRoller],
        'step' => [Equipment::Step],
        'towel' => [Equipment::Towel],

        // Hito Provider-Agnostic Normalization (Audit #4) — 10 valores
        // reales del catálogo vivo de YMove, antes sin mapeo (caían
        // silenciosamente en `equipment_needed=[]` vía el fallback de
        // mapEquipment(), ver ese método). Clasificación decidida caso por
        // caso (ver docs/DECISIONS.md), nunca "agregar todo lo nuevo":
        // rings/dip bar/bosu/plate/suspension trainer/battle rope son
        // objetos físicamente distintos de cualquier valor ya existente →
        // casos nuevos de Equipment; mini band/trap bar/ez bar son
        // variantes de equipo ya representado, con pérdida de granularidad
        // aceptada; push-up handles se trata como accesorio de confort que
        // no cambia la demanda real del movimiento (equivalente a sin
        // equipo). `ab wheel` se deja deliberadamente FUERA de este mapa
        // (volumen mínimo, 1 ejercicio) — cae en el fallback `Unsupported`.
        'rings' => [Equipment::Rings],
        'dip bar' => [Equipment::DipBar],
        'bosu' => [Equipment::Bosu],
        'plate' => [Equipment::Plate],
        'suspension trainer' => [Equipment::SuspensionTrainer],
        'battle rope' => [Equipment::BattleRope],
        'mini band' => [Equipment::ResistanceBands],
        'trap bar' => [Equipment::Barbell],
        'ez bar' => [Equipment::Barbell],
        'push-up handles' => [],
    ];

    /**
     * `tracking_type` es la ÚNICA excepción a "nunca inventar": la columna
     * no admite null (siempre existió con default `reps_and_load`, ver
     * migración de Hito 9.1) y YMove no distingue explícitamente
     * "por repeticiones" de "por tiempo". Heurística best-effort por
     * palabra clave, documentada como tal — cualquier ejercicio así
     * derivado sigue sujeto a revisión humana antes de `activate()`.
     */
    private const TIME_BASED_KEYWORDS = ['stretch', 'pose', 'hold', 'plank', 'mobility', 'isometric'];

    /**
     * Hito 15.2 — auditoría real (incidente contact_id=28, exercise_id=710
     * "Pull Up (Neutral Grip)" servido a una usuaria `home` sin barra de
     * dominadas): YMove etiqueta como `equipment: "bodyweight"` tanto
     * ejercicios que genuinamente no requieren nada (Bodyweight Squat,
     * Plank, Push Ups...) como ejercicios que SÍ exigen un aparato o
     * superficie fija pese a no sumar carga externa (Pull Up → barra de
     * dominadas; Bench Dips → banco). YMove no expone ningún campo
     * estructurado que distinga ambos casos — se comparó `category` y
     * `exerciseType` entre ejemplos confirmados de cada grupo y no hay
     * ningún patrón consistente. La única señal disponible es el texto.
     *
     * Auditados los 61 ejercicios activos de YMove (Hito 15.2): de 15 con
     * `equipment` crudo `bodyweight`, solo 2 tienen una frase inequívoca
     * en su texto (710 → "pull-up bar" en la description; 81 → "bench" en
     * el name/description) — los otros 13 no mencionan ningún aparato/
     * superficie y correctamente siguen resolviendo a `[]`.
     *
     * Deliberadamente conservador y acotado a frases que ya son claves
     * reales de EQUIPMENT_MAP (no se inventa vocabulario nuevo): un
     * término de una sola palabra común como "step" queda fuera a
     * propósito porque en este mismo catálogo aparece como VERBO ("step
     * your feet back" en Burpee/Half burpee) — un regex de palabra
     * completa no alcanza para desambiguar un verbo de un sustantivo en
     * inglés, y la instrucción explícita es no adivinar ante ambigüedad.
     * Si evidencia real futura (ej. al revisar el catálogo hoy inactivo)
     * muestra un caso inequívoco para box/chair/wall/dip station/etc., se
     * añade aquí una entrada más siguiendo el mismo patrón.
     *
     * Ante más de una señal distinta en el mismo texto se devuelven TODAS
     * (equipment_needed ya admite múltiples valores) en vez de elegir una
     * arbitrariamente. Ante ninguna señal reconocida se devuelve `[]` — el
     * ejercicio se preserva como "bodyweight puro", sujeto a la misma
     * revisión humana que ya aplica al resto del catálogo antes de
     * `activate()`.
     *
     * @var array<string, Equipment>
     */
    private const BODYWEIGHT_APPARATUS_SIGNALS = [
        '/\bpull[- ]up bar\b/i' => Equipment::PullUpBar,
        '/\bbench\b/i' => Equipment::Bench,
    ];

    public function normalize(ProviderExerciseData $raw): NormalizedExerciseData
    {
        $data = $raw->raw;

        $muscleGroupKey = $data['muscleGroup'] ?? null;
        $primaryMuscle = $this->mapMuscleGroup($muscleGroupKey, $raw->providerExerciseId, $data['title'] ?? null);

        $secondaryMuscles = collect($data['secondaryMuscles'] ?? [])
            ->map(fn ($m) => self::MUSCLE_GROUP_MAP[$m] ?? null)
            ->filter()
            ->values()
            ->all();

        $muscleGroupCoarse = self::MUSCLE_GROUP_COARSE_MAP[$muscleGroupKey] ?? ($muscleGroupKey ?? 'core');

        $equipmentNeeded = $this->mapEquipment($data['equipment'] ?? null, $data);

        // Pass-through directo — YMove ya devolvió `difficulty: null` en la
        // prueba técnica real (Barbell Hip Thrust); nunca se infiere.
        $difficulty = isset($data['difficulty']) && $data['difficulty'] !== null
            ? ExperienceLevel::tryFrom($data['difficulty'])
            : null;

        return new NormalizedExerciseData(
            provider: 'ymove',
            providerExerciseId: $raw->providerExerciseId,
            name: $data['title'] ?? "(ejercicio {$raw->providerExerciseId})",
            description: $data['description'] ?? null,
            instructions: array_values(array_filter($data['instructions'] ?? [], 'is_string')),
            importantPoints: array_values(array_filter($data['importantPoints'] ?? [], 'is_string')),
            primaryMuscle: $primaryMuscle,
            secondaryMuscles: $secondaryMuscles,
            muscleGroupCoarse: $muscleGroupCoarse,
            movementPattern: null,
            difficultyLevel: $difficulty,
            equipmentNeeded: $equipmentNeeded,
            trackingType: $this->inferTrackingType($data),
            // YMove no provee esto en absoluto (shape auditado) — nunca se
            // deriva de instructions/importantPoints. Solo existe si un
            // humano lo cura después.
            commonMistakes: [],
            breathingCue: null,
            exerciseType: $this->mapExerciseType($data['exerciseType'] ?? [], $raw->providerExerciseId, $data['title'] ?? null),
            videoDurationSeconds: $data['videoDurationSecs'] ?? null,
            rawMetadata: $data,
            hasVideo: isset($data['hasVideo']) ? (bool) $data['hasVideo'] : null,
        );
    }

    /**
     * Hito 9.3 (post-deploy) — extraído a método público (antes en línea
     * dentro de normalize()) para que un backfill local (ej.
     * `exercises:backfill-equipment`) pueda re-derivar `equipment_needed`
     * de ejercicios YA sincronizados a partir del `equipment` crudo que
     * cada fila ya conserva en `provider_metadata` — sin volver a llamar a
     * YMove. Misma tabla, mismo resultado que durante un sync real.
     *
     * Hito 15.2 — segundo parámetro opcional (compatible hacia atrás: todo
     * llamador que solo pasaba el string crudo sigue funcionando igual,
     * ahora simplemente sin refinamiento de `bodyweight`). Cuando se
     * provee, es el payload crudo COMPLETO del ejercicio (mismo shape que
     * `provider_metadata`, ya que es literalmente ese valor en un
     * backfill) — necesario porque distinguir "bodyweight puro" de
     * "bodyweight + aparato" exige leer texto (title/description/
     * instructions/importantPoints), no solo el string de equipment. Ver
     * `refineBodyweightEquipment()`.
     *
     * @param  array<string, mixed>|null  $rawData
     * @return array<int, string> valores de App\Training\Enums\Equipment
     */
    public function mapEquipment(?string $rawEquipment, ?array $rawData = null): array
    {
        $key = mb_strtolower(trim((string) $rawEquipment));

        if ($key === 'bodyweight' && $rawData !== null) {
            $refined = $this->refineBodyweightEquipment($rawData);

            if ($refined !== []) {
                return array_map(fn (Equipment $e) => $e->value, $refined);
            }
        }

        // Hito Provider-Agnostic Normalization — distinción explícita entre
        // "clave conocida cuyo valor es []" (ej. 'bodyweight' genuino, 'push-up
        // handles') y "clave que este normalizer nunca ha visto". Antes de este
        // hito ambos casos usaban `?? []` y eran indistinguibles — un ejercicio
        // que SÍ exigía equipo (ej. 'rings', histórico) podía terminar
        // representado igual que uno genuinamente sin equipo. `array_key_exists`
        // en vez de `??` es la única forma de separarlos correctamente.
        if (! array_key_exists($key, self::EQUIPMENT_MAP)) {
            Log::warning('EXERCISE_EQUIPMENT_UNSUPPORTED', [
                'provider' => 'ymove',
                'provider_exercise_id' => $rawData['id'] ?? null,
                'exercise_name' => $rawData['title'] ?? null,
                'raw_equipment' => $rawEquipment,
                'normalized_key' => $key,
            ]);

            return [Equipment::Unsupported->value];
        }

        return array_map(fn (Equipment $e) => $e->value, self::EQUIPMENT_MAP[$key]);
    }

    /**
     * Hito Provider-Agnostic Normalization — mismo criterio que
     * mapEquipment(): un `muscleGroup` desconocido nunca se convierte en
     * un `MuscleFocus` inventado, y se distingue explícitamente un dato
     * INVÁLIDO del proveedor (ver INVALID_MUSCLE_GROUP_VALUES — YMove puso
     * ahí algo que no es un músculo en absoluto) de un valor simplemente
     * NO SOPORTADO todavía (una zona real que nuestro vocabulario de 11
     * focos no distingue con esa granularidad, ej. "legs"/"forearms").
     * Ambos resultan en `null` — la diferencia es solo observabilidad
     * (nivel y razón del log), nunca el valor persistido: a diferencia de
     * `equipment_needed` (donde `[]` SÍ tiene consecuencias de elegibilidad,
     * ver TrainingEngine::isEligible()), `primary_muscle=null` ya es
     * tratado de forma segura por `TrainingEngine::selectExercises()` (el
     * ejercicio simplemente no entra en ningún tier de foco) — no hace
     * falta un sentinel de dominio nuevo para esto.
     */
    private function mapMuscleGroup(?string $muscleGroupKey, string $providerExerciseId, ?string $exerciseName): ?MuscleFocus
    {
        if ($muscleGroupKey === null) {
            return null;
        }

        if (isset(self::MUSCLE_GROUP_MAP[$muscleGroupKey])) {
            return self::MUSCLE_GROUP_MAP[$muscleGroupKey];
        }

        if (in_array($muscleGroupKey, self::INVALID_MUSCLE_GROUP_VALUES, true)) {
            Log::info('EXERCISE_MUSCLE_GROUP_INVALID', [
                'provider' => 'ymove',
                'provider_exercise_id' => $providerExerciseId,
                'exercise_name' => $exerciseName,
                'raw_muscle_group' => $muscleGroupKey,
            ]);

            return null;
        }

        Log::info('EXERCISE_MUSCLE_GROUP_UNSUPPORTED', [
            'provider' => 'ymove',
            'provider_exercise_id' => $providerExerciseId,
            'exercise_name' => $exerciseName,
            'raw_muscle_group' => $muscleGroupKey,
        ]);

        return null;
    }

    /**
     * Hito Provider-Agnostic Normalization — traduce el `exerciseType[]`
     * crudo de YMove al vocabulario propio (`ExerciseType`), nunca un
     * pass-through del string del proveedor (a diferencia del
     * comportamiento anterior). Un valor no reconocido se descarta — nunca
     * se inventa un caso de enum nuevo solo para "no perder el dato" (el
     * dato crudo completo ya sobrevive en `provider_metadata`, ver
     * Exercise::$provider_metadata). Un solo log agregado por ejercicio
     * (no uno por valor) para no generar ruido en una sincronización
     * completa del catálogo.
     *
     * @param  array<int, mixed>  $rawTypes
     * @return array<int, string> valores de App\Training\Enums\ExerciseType
     */
    private function mapExerciseType(array $rawTypes, string $providerExerciseId, ?string $exerciseName): array
    {
        $mapped = [];
        $discarded = [];

        foreach (array_filter($rawTypes, 'is_string') as $raw) {
            $type = ExerciseType::tryFrom($raw);

            if ($type !== null) {
                $mapped[] = $type->value;
            } else {
                $discarded[] = $raw;
            }
        }

        if ($discarded !== []) {
            Log::info('EXERCISE_TYPE_UNSUPPORTED', [
                'provider' => 'ymove',
                'provider_exercise_id' => $providerExerciseId,
                'exercise_name' => $exerciseName,
                'raw_exercise_type_discarded' => $discarded,
            ]);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data  payload crudo completo del ejercicio
     * @return array<int, Equipment>
     */
    private function refineBodyweightEquipment(array $data): array
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $data['title'] ?? '',
            $data['description'] ?? '',
            implode(' ', array_filter($data['instructions'] ?? [], 'is_string')),
            implode(' ', array_filter($data['importantPoints'] ?? [], 'is_string')),
        ])));

        $found = [];

        foreach (self::BODYWEIGHT_APPARATUS_SIGNALS as $pattern => $equipment) {
            if (preg_match($pattern, $haystack) === 1) {
                $found[] = $equipment;
            }
        }

        return $found;
    }

    private function inferTrackingType(array $data): TrackingType
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $data['title'] ?? '',
            $data['category'] ?? '',
            implode(' ', $data['exerciseType'] ?? []),
        ])));

        foreach (self::TIME_BASED_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return TrackingType::TimeBased;
            }
        }

        return TrackingType::RepsAndLoad;
    }
}
