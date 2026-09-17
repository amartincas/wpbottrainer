<?php

/**
 * Duración objetivo de sesión — guardrail arquitectónico, mismo patrón
 * exacto que tests/Feature/Referrals/ReferralIsolationArchTest.php y
 * tests/Feature/Acquisition/AcquisitionIsolationArchTest.php: garantiza que
 * ninguno de los 3 componentes nuevos de esta funcionalidad consulta IA ni
 * conoce ningún servicio/proveedor de IA — la cantidad de ejercicios, la
 * estimación de duración y la introducción de sesión son completamente
 * deterministas en este MVP. Si este test falla, alguien introdujo una
 * dependencia que el diseño prohíbe explícitamente.
 */

const AI_SERVICE_DEPENDENCIES = [
    'App\Services\AI',
    'App\Contracts\AiServiceInterface',
    'App\Factories\AIServiceFactory',
    'App\Training\Support\CoachService',
    'App\Training\Support\ExecutionReportService',
    'App\Training\Support\OnboardingConversationService',
];

arch('TrainingEngine no depende de ningún servicio de IA — la cantidad de ejercicios es 100% determinista')
    ->expect('App\Training\Engine\TrainingEngine')
    ->not->toUse(AI_SERVICE_DEPENDENCIES);

arch('DurationEstimator no depende de ningún servicio de IA — solo aritmética sobre prescripciones ya decididas')
    ->expect('App\Training\Support\DurationEstimator')
    ->not->toUse(AI_SERVICE_DEPENDENCIES);

arch('SessionIntroComposer no depende de ningún servicio de IA — la introducción del MVP es determinista, sin IA')
    ->expect('App\Training\Support\SessionIntroComposer')
    ->not->toUse(AI_SERVICE_DEPENDENCIES);
