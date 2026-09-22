<?php

namespace App\Training\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\PreRoutingScreenInterface;
use App\Models\Contact;
use App\Training\Handlers\TrainingHandler;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Hito A (Safety Precedence, hallazgo de la prueba E2E real) — corrige un
 * secuestro real de precedencia: `CustomerServiceEscalationIntentClassifier`
 * (Tier 0 del Router, `Router.php`) corre SIEMPRE antes que cualquier
 * classifier de Training, y su vocabulario cerrado incluye la frase
 * genérica "tengo un problema" — que colisiona con una declaración de
 * salud real durante el health screening ("tengo un problema de
 * desviación en la columna"), enviándola a Customer Care en vez de
 * `HealthScreeningRequirement::apply()`.
 *
 * Este screen NO modifica `CustomerServiceEscalationDetector` (seguiría
 * rompiendo Customer Care fuera de este contexto muy acotado) ni el
 * Router — corre ANTES de ambos, exactamente como `SafetySignalPreRoutingScreen`
 * ya corre antes de cualquier clasificación por el mismo motivo ("la
 * seguridad no puede depender del routing normal").
 *
 * La señal usada es `OnboardingRequirementRegistry::firstPendingBlocking()`
 * — la ÚNICA autoridad ya existente sobre "cuál es la pregunta bloqueante
 * actual" (ver su docblock). Si esa pregunta es exactamente
 * `HealthScreeningRequirement` (`key() === 'health_screening'`), el turno
 * actual es, con certeza determinista, la respuesta esperada a la
 * pregunta de salud — sin ningún análisis de texto ni heurística nueva.
 *
 * Deliberadamente NO reclama el pipeline (`return false`) en ningún otro
 * caso — un Contact sin `TrainingProfile`, o cuya pregunta bloqueante
 * actual es otra (nombre/objetivo/nivel/etc.), deja el mensaje seguir su
 * curso normal por Router/Dispatcher sin ninguna alteración.
 *
 * Corrección (revisión pre-commit de Hito A) — gate explícito de dominio
 * ANTES de cualquier consulta: este screen se registra en `PreRoutingScreener`
 * sin condición de tenant (mismo patrón que `SafetySignalPreRoutingScreen`/
 * `ReferralAttributionPreRoutingScreen`), así que corría para TODO mensaje
 * de TODO tenant — incluidos los de e-commerce/legacy, que nunca podrían
 * tener `health_screening` como pregunta bloqueante (no tienen
 * `TrainingProfile`), pero igual pagaban 2 queries (`Contact` +
 * `trainingProfile` lazy-load) en cada mensaje. `$context->tenant->primary_domain`
 * ya viene cargado en `ExecutionContext` (mismo dato que usa
 * `TrainingDomainFallbackClaim`) — comparado ANTES de tocar `Contact`, sin
 * ninguna consulta nueva para descartar el caso no-Training.
 */
class HealthScreeningPrecedencePreRoutingScreen implements PreRoutingScreenInterface
{
    public function __construct(
        private readonly OnboardingRequirementRegistry $registry,
        private readonly TrainingHandler $trainingHandler,
    ) {}

    public function screen(ExecutionContext $context): bool
    {
        if ($context->tenant->primary_domain !== 'training') {
            return false;
        }

        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        if ($contact === null) {
            return false;
        }

        $profile = $contact->trainingProfile;

        if ($profile === null) {
            return false;
        }

        $requirement = $this->registry->firstPendingBlocking($profile, $contact);

        if ($requirement?->key() !== 'health_screening') {
            return false;
        }

        Log::info('HEALTH_SCREENING_PRECEDENCE_CLAIMED', [
            'tenant_id' => $context->tenant->id,
            'customer_phone' => $context->message->from,
            'contact_id' => $contact->id,
        ]);

        // TrainingHandler::handle() es la única fuente de verdad para
        // procesar este turno (incluida la llamada real a
        // HealthScreeningRequirement::apply() vía el onboarding) — no se
        // reescribe esa lógica aquí, solo se garantiza que se alcance sin
        // importar cómo habría clasificado el Router (mismo mecanismo que
        // SafetySignalPreRoutingScreen ya usa).
        $this->trainingHandler->handle($context);

        return true;
    }
}
