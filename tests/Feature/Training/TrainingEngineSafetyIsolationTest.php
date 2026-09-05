<?php

/**
 * Hito de seguridad de restricciones — prueba de arquitectura (mismo
 * estilo que el test que verifica que ExerciseResource no contiene lógica
 * específica de YMove): TrainingEngine debe permanecer completamente
 * aislado del mecanismo de restricciones (nuevo Y legacy). Solo debe
 * conocer SafetyRestrictionResolver como colaborador — nunca
 * TrainingRestriction, DeclaredHealthCondition, los enums de source/status,
 * revisión humana, ni TrainingProfile.restrictions directamente.
 */
it('TrainingEngine never references TrainingRestriction, DeclaredHealthCondition, or the safety enums directly', function () {
    $contents = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    foreach (['TrainingRestriction', 'DeclaredHealthCondition', 'RestrictionSource', 'RestrictionStatus', 'reviewed_by', 'reviewed_at'] as $forbidden) {
        expect($contents)->not->toContain($forbidden, "TrainingEngine.php no debe referenciar '{$forbidden}' — esa lógica vive en SafetyRestrictionResolver.");
    }
});

it('TrainingEngine never reads $profile->restrictions or $exercise->contraindications directly', function () {
    $contents = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    expect($contents)->not->toContain('->restrictions');
    expect($contents)->not->toContain('->contraindications');
});

it('TrainingEngine depends only on SafetyRestrictionResolver for safety, never on TrainingRestriction/DeclaredHealthCondition as an import', function () {
    $contents = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    expect($contents)->toContain('use App\Training\Support\SafetyRestrictionResolver;');
    expect($contents)->not->toContain('use App\Models\TrainingRestriction;');
    expect($contents)->not->toContain('use App\Models\DeclaredHealthCondition;');
});
