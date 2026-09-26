<?php

namespace App\Training\Support;

use App\Training\Enums\LoadModality;
use App\Training\Enums\TrackingType;

/**
 * Hito D (Bodyweight + carga + tracking + cargas inválidas, diseño formal
 * v2 aprobado, fase D3) — aplica la semántica final de un `Exercise`
 * concreto (`TrackingType`/`LoadModality`) sobre los sets YA normalizados
 * por `ExecutionReportService` (fase D2: rango `[0,999]`, conversión
 * lb→kg, `unit` ya resuelto, `no_external_load` ya extraído). Es el punto
 * de escritura DONDE por fin se conoce el ejercicio real, y por tanto el
 * ÚNICO lugar donde puede decidirse si un `load` es semánticamente válido.
 *
 * 100% puro/determinista: recibe únicamente enums de dominio (nunca
 * `Exercise`/`WorkoutExercise`/`WorkoutSession`/`Contact` — esa resolución
 * es responsabilidad exclusiva de `ExecutionReportRecorder`, que ya conoce
 * la identidad del `WorkoutExercise` reportado) y el array de sets ya
 * validado por D2. No abre transacción, no hace `lockForUpdate()`, no crea
 * ni modifica ningún modelo — nunca escribe en base de datos. Mismo
 * espíritu exacto que `ProgressionEvaluator::evaluate()` (recibe
 * `TrackingType` como parámetro, nunca un `Exercise`).
 *
 * `?TrackingType`/`?LoadModality` nulos representan "el `Exercise` fue
 * borrado físicamente" (`exercise_id` es `nullOnDelete`) — mismo fallback
 * exacto que ya usa `CoachContextProvider::buildExerciseSnapshot()` para
 * `tracking_type` (`?? TrackingType::RepsAndLoad`). `LoadModality::null`
 * representa AMBOS casos indistinguibles para esta validación: "el
 * `Exercise` no existe" y "el `Exercise` existe pero todavía no fue
 * curado" (`Exercise::load_modality === null`) — en cualquiera de los dos,
 * el fallback de compatibilidad es `Required` (comportamiento legacy:
 * aceptar la carga reportada sin restricción), EXCLUSIVAMENTE dentro de
 * esta validación — nunca se escribe de vuelta a `Exercise`, nunca se
 * infiere ni se persiste una clasificación implícita.
 */
class ExerciseSetValidator
{
    /**
     * @param  array<int, array{reps: ?int, load: ?float, unit: ?string, duration_seconds: ?int, no_external_load: bool}>  $sets
     *         forma exacta que entrega `ExecutionReportService::validateSets()`
     *         (fase D2) — `unit` ya no se usa aquí (la conversión de
     *         unidad ya ocurrió en D2; D3 nunca la repite).
     * @return array<int, array{reps: ?int, load: ?float, duration_seconds: ?int}>
     *         forma lista para `ExerciseSet::create()` — sin `unit` ni
     *         `no_external_load` (ya consumidos aquí), reindexada
     *         secuencialmente (los sets descartados por la segunda
     *         comprobación de vacío no dejan huecos).
     */
    public function sanitize(?TrackingType $trackingType, ?LoadModality $loadModality, array $sets): array
    {
        $trackingType ??= TrackingType::RepsAndLoad;
        $loadModality ??= LoadModality::Required;

        $sanitized = [];

        foreach ($sets as $set) {
            $reps = $trackingType === TrackingType::RepsAndLoad ? ($set['reps'] ?? null) : null;
            $duration = $trackingType === TrackingType::TimeBased ? ($set['duration_seconds'] ?? null) : null;
            $load = $this->resolveLoad($set, $loadModality);

            // Segunda comprobación de set vacío (DISTINTA de la que ya
            // aplicó ExecutionReportService en D2, que solo conocía
            // reps/load/duration crudos + no_external_load, sin saber
            // todavía si el ejercicio admite carga): un set puede llegar
            // aquí con datos, pero quedar completamente vacío DESPUÉS de
            // que la matriz de arriba anule reps/duration por
            // tracking_type incompatible, o load por LoadModality::None —
            // en ese caso nunca debe crearse un ExerciseSet.
            if ($reps === null && $load === null && $duration === null) {
                continue;
            }

            $sanitized[] = ['reps' => $reps, 'load' => $load, 'duration_seconds' => $duration];
        }

        return $sanitized;
    }

    /**
     * Único invariante para `LoadModality::None`: `load` es
     * INCONDICIONALMENTE `null` — nunca un valor positivo alucinado por el
     * LLM, y nunca `0` aunque `no_external_load=true` (un ejercicio
     * bodyweight nunca necesita "confirmar" ausencia de carga: siempre lo
     * está, por definición — `0` solo tiene sentido semántico para
     * `Required`, donde representa un evento real "esta vez sin peso" en
     * un ejercicio que normalmente sí lo espera).
     *
     * Para `Required`: precedencia fija — un número explícito SIEMPRE gana
     * sobre `no_external_load` (posible salida contradictoria/alucinada
     * del LLM, ver D2); solo si no hay número explícito, `no_external_load`
     * decide entre `0` (declarado explícitamente) y `null` (sin mención).
     */
    private function resolveLoad(array $set, LoadModality $loadModality): ?float
    {
        if ($loadModality === LoadModality::None) {
            return null;
        }

        $explicit = $set['load'] ?? null;

        if ($explicit !== null) {
            return $explicit;
        }

        return ($set['no_external_load'] ?? false) ? 0.0 : null;
    }
}
