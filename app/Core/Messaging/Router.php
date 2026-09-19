<?php

namespace App\Core\Messaging;

use Illuminate\Contracts\Container\Container;

/**
 * Classifies the current ExecutionContext into an Intent by trying each
 * registered IntentClassifier, grouped into ordered TIERS (Router →
 * Container → Classifier, the same resolution pattern as Dispatcher →
 * Container → Handler). Within a tier, the first classifier that recognizes
 * the message wins — but a tier is only tried once EVERY classifier in the
 * previous tier returned null. If no tier produces an Intent, the message is
 * Intent::FallbackChat — the same general-conversation behavior that existed
 * before any real classification was implemented (Hito 2).
 *
 * Precedencia de Intents (ver docs/DECISIONS.md): esta estructura de tiers
 * existe para que una señal EXPLÍCITA de un dominio (ej. una keyword de
 * Referral) nunca pueda perderse frente a una señal puramente CONTEXTUAL de
 * otro dominio (ej. una WorkoutSession pendiente de Training) — antes de
 * esto, un array plano dependía del orden accidental de registro para
 * resolver esa precedencia, y un classifier contextual registrado antes que
 * uno explícito le robaba el turno sin que el mensaje contuviera ninguna
 * señal de ese dominio.
 *
 * The Router itself contains zero domain knowledge: no keyword list, no
 * concept of "training" or any other vertical lives here, and no knowledge
 * of WHICH classifiers are "explicit" vs "contextual" — each concrete
 * classifier (e.g. App\Training\Support\TrainingIntentClassifier) owns its
 * own vocabulary, and its TIER MEMBERSHIP is decided entirely by
 * AppServiceProvider's registration, never by this class.
 */
class Router
{
    /**
     * @param array<int, array<int, class-string<IntentClassifierInterface>>> $tiers
     *        Tried in order; within a tier, the first non-null result wins.
     *        A tier is only evaluated if every classifier in every previous
     *        tier returned null.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $tiers,
    ) {}

    public function route(ExecutionContext $context): Intent
    {
        foreach ($this->tiers as $classifierClasses) {
            foreach ($classifierClasses as $classifierClass) {
                /** @var IntentClassifierInterface $classifier */
                $classifier = $this->container->make($classifierClass);

                $intent = $classifier->classify($context);

                if ($intent !== null) {
                    return $intent;
                }
            }
        }

        return Intent::FallbackChat;
    }
}
