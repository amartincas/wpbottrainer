<?php

/**
 * Bloque 4 — tests de arquitectura tipo grep, mismo patrón que
 * TrainingEngineSafetyIsolationTest.php (Bloque 1): verifican límites de
 * conocimiento entre piezas leyendo el código fuente real, no el
 * comportamiento en runtime.
 */
it('S: TrainingEngine never references anything from the Onboarding namespace', function () {
    $source = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    foreach (['OnboardingRequirement', 'OnboardingRequirementRegistry', 'OnboardingConversationComposer', 'QuestionContext', 'App\\Training\\Onboarding'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('R: TrainingEngine never imports HealthScreeningRequirement, DeclaredHealthCondition, or FunctionalLimitationCanonicalMapper — Bloque 5 no lo toca', function () {
    // Se busca un `use` real, no la mención en prosa dentro de un docblock
    // explicando la garantía de aislamiento (mismo patrón de falso positivo
    // ya visto en otros tests de esta suite).
    $source = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));

    foreach (['HealthScreeningRequirement', 'DeclaredHealthCondition', 'FunctionalLimitationCanonicalMapper'] as $forbidden) {
        expect($source)->not->toMatch("/use [A-Za-z\\\\]*{$forbidden};/");
    }
});

it('R: HealthScreeningRequirement never imports TrainingEngine — el bloqueo vive en TrainingAccessGate, nunca en el motor', function () {
    $source = file_get_contents(app_path('Training/Onboarding/Requirements/HealthScreeningRequirement.php'));

    expect($source)->not->toContain('TrainingEngine');
});

it('C (arquitectura): OnboardingConversationService never imports/instantiates any concrete OnboardingRequirement class', function () {
    // Se busca uso real (import/instanciación/type-hint), no menciones en
    // comentarios explicativos — el propio docblock del archivo describe la
    // relación con el Registry en prosa, lo cual es esperado y no una
    // violación del límite arquitectónico.
    $source = file_get_contents(app_path('Training/Support/OnboardingConversationService.php'));

    expect($source)->not->toMatch('/use App\\\\Training\\\\Onboarding\\\\Requirements/');
    expect($source)->not->toMatch('/new [A-Za-z]+Requirement\(/');
});

it('J: RestrictionsRequirement never imports TrainingRestriction or DeclaredHealthCondition — it stays the legacy free-text mechanism, not the safety solution', function () {
    // Igual que arriba: se busca un `use` real de esas clases, no la mera
    // mención en el docblock explicando por qué NO las usa.
    $source = file_get_contents(app_path('Training/Onboarding/Requirements/RestrictionsRequirement.php'));

    expect($source)->not->toMatch('/use App\\\\Models\\\\TrainingRestriction;/');
    expect($source)->not->toMatch('/use App\\\\Models\\\\DeclaredHealthCondition;/');
});

it('none of the 10 OnboardingRequirement implementations reference AiServiceInterface or a concrete AI provider', function () {
    $files = glob(app_path('Training/Onboarding/Requirements/*.php'));

    expect($files)->toHaveCount(10); // 9 del Bloque 4 + HealthScreeningRequirement (Bloque 5)

    foreach ($files as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toContain('AiServiceInterface');
        expect($source)->not->toContain('AIServiceFactory');
    }
});

it('OnboardingConversationComposer never calls an AI service — Opción A aprobada, cero segunda llamada de IA', function () {
    $source = file_get_contents(app_path('Training/Onboarding/OnboardingConversationComposer.php'));

    expect($source)->not->toContain('AiServiceInterface');
    expect($source)->not->toContain('AIServiceFactory');
    expect($source)->not->toContain('getResponse');
});
