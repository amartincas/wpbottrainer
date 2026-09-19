<?php

use App\Core\Messaging\ContextualIntentClassifierInterface;
use App\Core\Messaging\Router;
use App\CustomerCare\Support\CustomerServiceEscalationIntentClassifier;
use App\CustomerCare\Support\FaqLikelyIntentClassifier;

/**
 * Precedencia de Intents (ver docs/DECISIONS.md) — guardrail estructural.
 * La estructura real tiene 4 tiers: [0] escalamiento, [1] explícito de
 * dominio, [2] contextual, [3] Faq (broad catch-all, hallazgo real durante
 * la validación de esta corrección — ver AppServiceProvider). Este test
 * verifica las invariantes SIN mantener una lista manual de nombres de
 * classifiers "explícitos" — usa la interfaz marcadora
 * ContextualIntentClassifierInterface para identificar dinámicamente cuáles
 * son contextuales, y trata a CustomerServiceEscalationIntentClassifier/
 * FaqLikelyIntentClassifier como los dos roles especiales ya documentados
 * (siempre primero / siempre último) — un futuro classifier "explícito de
 * dominio" nuevo (Subscription, Injury Reporting, etc.) queda cubierto
 * automáticamente sin tocar este test.
 */
function routerTiers(): array
{
    $router = app(Router::class);

    $reflection = new ReflectionProperty(Router::class, 'tiers');
    $reflection->setAccessible(true);

    return $reflection->getValue($router);
}

function tierIndexOf(array $tiers, string $classifierClass): ?int
{
    foreach ($tiers as $index => $classifierClasses) {
        if (in_array($classifierClass, $classifierClasses, true)) {
            return $index;
        }
    }

    return null;
}

it('CustomerServiceEscalationIntentClassifier stays alone in the very first tier', function () {
    $tiers = routerTiers();

    expect($tiers[0])->toBe([CustomerServiceEscalationIntentClassifier::class]);
});

it('FaqLikelyIntentClassifier stays alone in the very last tier', function () {
    $tiers = routerTiers();

    expect($tiers[array_key_last($tiers)])->toBe([FaqLikelyIntentClassifier::class]);
});

it('no classifier marked as contextual appears before every non-contextual, non-escalation, non-Faq (i.e. "explicit domain") classifier', function () {
    $tiers = routerTiers();

    $explicitDomainTierIndexes = [];
    $contextualTierIndexes = [];

    foreach ($tiers as $tierIndex => $classifierClasses) {
        foreach ($classifierClasses as $classifierClass) {
            if ($classifierClass === CustomerServiceEscalationIntentClassifier::class || $classifierClass === FaqLikelyIntentClassifier::class) {
                continue;
            }

            if (is_a($classifierClass, ContextualIntentClassifierInterface::class, true)) {
                $contextualTierIndexes[] = $tierIndex;
            } else {
                $explicitDomainTierIndexes[] = $tierIndex;
            }
        }
    }

    expect($explicitDomainTierIndexes)->not->toBeEmpty();
    expect($contextualTierIndexes)->not->toBeEmpty();

    // Todo classifier explícito de dominio debe estar en un tier ANTERIOR
    // a cualquier classifier contextual — el núcleo de la corrección real.
    expect(max($explicitDomainTierIndexes))->toBeLessThan(min($contextualTierIndexes));
});

it('Faq is tried strictly after every domain classifier, explicit or contextual — never at the same level', function () {
    $tiers = routerTiers();
    $faqTierIndex = tierIndexOf($tiers, FaqLikelyIntentClassifier::class);

    foreach ($tiers as $tierIndex => $classifierClasses) {
        foreach ($classifierClasses as $classifierClass) {
            if ($classifierClass === FaqLikelyIntentClassifier::class || $classifierClass === CustomerServiceEscalationIntentClassifier::class) {
                continue;
            }

            expect($tierIndex)->toBeLessThan($faqTierIndex, "{$classifierClass} (tier {$tierIndex}) debería resolverse antes que FaqLikelyIntentClassifier (tier {$faqTierIndex}).");
        }
    }
});
