<?php

namespace App\Core\Messaging;

use Illuminate\Contracts\Container\Container;

/**
 * Classifies the current ExecutionContext into an Intent by trying each
 * registered IntentClassifier in order (Router → Container → Classifier,
 * the same resolution pattern as Dispatcher → Container → Handler). The
 * first classifier that recognizes the message wins; if none do, the
 * message is Intent::FallbackChat — the same general-conversation behavior
 * that existed before any real classification was implemented (Hito 2).
 *
 * The Router itself contains zero domain knowledge: no keyword list, no
 * concept of "training" or any other vertical lives here. Each concrete
 * classifier (e.g. App\Training\Support\TrainingIntentClassifier) owns that
 * vocabulary and is registered from AppServiceProvider — adding a new
 * classifier never requires touching this class.
 */
class Router
{
    /**
     * @param array<int, class-string<IntentClassifierInterface>> $classifierClasses
     *        Tried in order; the first non-null result wins.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $classifierClasses,
    ) {}

    public function route(ExecutionContext $context): Intent
    {
        foreach ($this->classifierClasses as $classifierClass) {
            /** @var IntentClassifierInterface $classifier */
            $classifier = $this->container->make($classifierClass);

            $intent = $classifier->classify($context);

            if ($intent !== null) {
                return $intent;
            }
        }

        return Intent::FallbackChat;
    }
}
