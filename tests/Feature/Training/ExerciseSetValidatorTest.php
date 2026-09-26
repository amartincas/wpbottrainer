<?php

use App\Training\Enums\LoadModality;
use App\Training\Enums\TrackingType;
use App\Training\Support\ExerciseSetValidator;

/**
 * Hito D (diseño formal v2 aprobado, fase D3) — `ExerciseSetValidator`.
 * 100% puro/determinista: sin BD, sin Eloquent, sin transacción — solo
 * enums de dominio + arrays. Cada set de entrada usa exactamente la forma
 * que entrega `ExecutionReportService::validateSets()` (fase D2, ya
 * normalizada: rango [0,999], lb→kg convertido, `unit` resuelto).
 */
function esvSet(?int $reps = null, ?float $load = null, ?string $unit = null, ?int $duration = null, bool $noExternalLoad = false): array
{
    return ['reps' => $reps, 'load' => $load, 'unit' => $unit, 'duration_seconds' => $duration, 'no_external_load' => $noExternalLoad];
}

function esv(): ExerciseSetValidator
{
    return new ExerciseSetValidator;
}

// ════════════════════════════════════════════════════════════════════
// Matriz obligatoria — RepsAndLoad
// ════════════════════════════════════════════════════════════════════

it('1. RepsAndLoad + None: reps se conserva, load siempre null, duration siempre null', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::None, [
        esvSet(reps: 10, load: 20.0, unit: 'kg'),
    ]);

    expect($result)->toBe([['reps' => 10, 'load' => null, 'duration_seconds' => null]]);
});

it('2. RepsAndLoad + None: no_external_load=true nunca genera carga (0 ni ningún otro valor)', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::None, [
        esvSet(reps: 10, noExternalLoad: true),
    ]);

    expect($result)->toBe([['reps' => 10, 'load' => null, 'duration_seconds' => null]]);
});

it('3. RepsAndLoad + Required: load null permanece null', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, load: null),
    ]);

    expect($result[0]['load'])->toBeNull();
});

it('4. RepsAndLoad + Required: no_external_load=true (sin número) resuelve a 0', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, noExternalLoad: true),
    ]);

    expect($result[0]['load'])->toBe(0.0);
});

it('5. RepsAndLoad + Required: carga explícita se conserva', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, load: 40.0, unit: 'kg'),
    ]);

    expect($result[0]['load'])->toBe(40.0);
});

it('6. RepsAndLoad + Required: carga explícita + no_external_load=true simultáneos — gana la carga explícita', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, load: 40.0, unit: 'kg', noExternalLoad: true),
    ]);

    expect($result[0]['load'])->toBe(40.0);
});

// ════════════════════════════════════════════════════════════════════
// Matriz obligatoria — TimeBased
// ════════════════════════════════════════════════════════════════════

it('7. TimeBased + None: duration se conserva, reps siempre null, load siempre null', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::None, [
        esvSet(duration: 60),
    ]);

    expect($result)->toBe([['reps' => null, 'load' => null, 'duration_seconds' => 60]]);
});

it('8. TimeBased + None con load enviado: el load igual se anula', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::None, [
        esvSet(load: 15.0, unit: 'kg', duration: 60),
    ]);

    expect($result)->toBe([['reps' => null, 'load' => null, 'duration_seconds' => 60]]);
});

it('8b. (D6 — cierre de hueco) TimeBased + None: reps Y load enviados simultáneamente — ambos se descartan, duration se conserva', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::None, [
        esvSet(reps: 10, load: 15.0, unit: 'kg', duration: 60),
    ]);

    expect($result)->toBe([['reps' => null, 'load' => null, 'duration_seconds' => 60]]);
});

it('9. TimeBased + Required: load null permanece null, duration se conserva', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::Required, [
        esvSet(duration: 45, load: null),
    ]);

    expect($result)->toBe([['reps' => null, 'load' => null, 'duration_seconds' => 45]]);
});

it('10. TimeBased + Required: no_external_load=true (sin número) resuelve a 0', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::Required, [
        esvSet(duration: 45, noExternalLoad: true),
    ]);

    expect($result[0]['load'])->toBe(0.0);
    expect($result[0]['duration_seconds'])->toBe(45);
});

it('11. TimeBased + Required: carga explícita se conserva (ej. Weighted Vest Plank)', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::Required, [
        esvSet(duration: 45, load: 5.0, unit: 'kg'),
    ]);

    expect($result)->toBe([['reps' => null, 'load' => 5.0, 'duration_seconds' => 45]]);
});

it('12. TimeBased + Required: carga explícita + no_external_load=true simultáneos — gana la carga explícita', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::Required, [
        esvSet(duration: 45, load: 5.0, unit: 'kg', noExternalLoad: true),
    ]);

    expect($result[0]['load'])->toBe(5.0);
});

// ════════════════════════════════════════════════════════════════════
// Compatibilidad, segunda comprobación de vacío, e invariantes de None
// ════════════════════════════════════════════════════════════════════

it('13. LoadModality null (Exercise sin clasificar) se comporta como Required — nunca como None', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, null, [
        esvSet(reps: 10, load: 40.0, unit: 'kg'),
    ]);

    expect($result[0]['load'])->toBe(40.0); // se acepta la carga -> comportamiento Required

    $withoutLoad = esv()->sanitize(TrackingType::RepsAndLoad, null, [
        esvSet(reps: 10, noExternalLoad: true),
    ]);

    expect($withoutLoad[0]['load'])->toBe(0.0); // no_external_load también resuelve como Required
});

it('14. un set completamente vacío después de la sanitización se descarta por completo', function () {
    // RepsAndLoad + None, el set solo traía load (que se anula) — sin reps
    // ni duration, el resultado final queda {reps:null, load:null,
    // duration:null} -> debe desaparecer, no persistirse vacío.
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::None, [
        esvSet(load: 20.0, unit: 'kg'),
    ]);

    expect($result)->toBe([]);
});

it('15. RepsAndLoad nunca persiste duration_seconds, incluso si D2 lo entregó', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, duration: 999), // dato incompatible, alucinado o mal clasificado
    ]);

    expect($result[0]['duration_seconds'])->toBeNull();
    expect($result[0]['reps'])->toBe(10); // el campo compatible sobrevive
});

it('16. TimeBased nunca persiste reps, incluso si D2 lo entregó', function () {
    $result = esv()->sanitize(TrackingType::TimeBased, LoadModality::Required, [
        esvSet(reps: 10, duration: 45), // dato incompatible
    ]);

    expect($result[0]['reps'])->toBeNull();
    expect($result[0]['duration_seconds'])->toBe(45); // el campo compatible sobrevive
});

it('17. LoadModality::None nunca persiste un load positivo, sin importar tracking_type', function () {
    $repsAndLoad = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::None, [esvSet(reps: 10, load: 999.0, unit: 'kg')]);
    $timeBased = esv()->sanitize(TrackingType::TimeBased, LoadModality::None, [esvSet(duration: 10, load: 999.0, unit: 'kg')]);

    expect($repsAndLoad[0]['load'])->toBeNull();
    expect($timeBased[0]['load'])->toBeNull();
});

it('18. LoadModality::None nunca convierte no_external_load en 0 — siempre null', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::None, [
        esvSet(reps: 10, noExternalLoad: true),
    ]);

    expect($result[0]['load'])->toBeNull();
    expect($result[0]['load'])->not->toBe(0.0);
});

// ── Regresión adicional: múltiples sets independientes, resultado reindexado ──

it('regression: multiple sets are sanitized independently and the discard reindexes sequentially, without gaps', function () {
    $result = esv()->sanitize(TrackingType::RepsAndLoad, LoadModality::Required, [
        esvSet(reps: 10, load: 40.0, unit: 'kg'),
        esvSet(load: 20.0, unit: 'kg'), // reps null, load Required real -> sobrevive (load no se anula aquí)
        esvSet(reps: null, load: null, duration: null, noExternalLoad: false), // vacío real -> se descarta
        esvSet(reps: 8, noExternalLoad: true),
    ]);

    expect($result)->toHaveCount(3); // el tercer elemento (vacío real) desaparece
    expect(array_keys($result))->toBe([0, 1, 2]); // reindexado, sin huecos
    expect($result[2]['load'])->toBe(0.0);
});
