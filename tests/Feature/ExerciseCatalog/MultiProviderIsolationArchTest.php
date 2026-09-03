<?php

/**
 * Guardrail arquitectónico (Hito 9.1): TrainingEngine, TrainingHandler y
 * MediaResolver solo pueden conocer App\ExerciseCatalog\Contracts\* y
 * App\ExerciseCatalog\ProviderRegistry — NUNCA una implementación concreta
 * de proveedor (YMove o cualquier futuro). Si este test falla, significa
 * que alguien acopló el dominio de Training a un proveedor específico.
 */

arch('TrainingEngine does not depend on any concrete exercise provider')
    ->expect('App\Training\Engine\TrainingEngine')
    ->not->toUse('App\ExerciseCatalog\Providers');

arch('TrainingHandler does not depend on any concrete exercise provider')
    ->expect('App\Training\Handlers\TrainingHandler')
    ->not->toUse('App\ExerciseCatalog\Providers');

arch('MediaResolver depends only on the ProviderRegistry, never on a concrete provider implementation')
    ->expect('App\ExerciseCatalog\MediaResolver')
    ->not->toUse('App\ExerciseCatalog\Providers');

/**
 * Comprobación literal adicional, pedida explícitamente en la revisión de
 * Hito 9: ni el texto "ymove"/"YMove" aparece en ninguno de los dos
 * archivos, sin importar la vía (import, string, comentario).
 */
it('never mentions "ymove" anywhere in TrainingEngine or TrainingHandler, literally', function () {
    $trainingEngineSource = file_get_contents(app_path('Training/Engine/TrainingEngine.php'));
    $trainingHandlerSource = file_get_contents(app_path('Training/Handlers/TrainingHandler.php'));
    $mediaResolverCallerButOwnFile = file_get_contents(app_path('ExerciseCatalog/MediaResolver.php'));

    expect(mb_strtolower($trainingEngineSource))->not->toContain('ymove');
    expect(mb_strtolower($trainingHandlerSource))->not->toContain('ymove');
    expect(mb_strtolower($mediaResolverCallerButOwnFile))->not->toContain('ymove');
});
