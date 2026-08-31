<?php

namespace App\Training\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\PreRoutingScreenInterface;
use App\Training\Handlers\TrainingHandler;
use Illuminate\Support\Facades\Log;

/**
 * Hito 7 (hallazgo de la prueba E2E real): antes de este screen, una señal
 * de seguridad solo se detectaba SI el Router ya había clasificado el
 * mensaje como `training` — lo cual depende de que haya una WorkoutSession
 * pendiente o el onboarding esté incompleto (ver
 * App\Training\Support\TrainingIntentClassifier). Un usuario ya onboardeado,
 * sin sesión activa, reportando dolor de pecho, nunca llegaba a
 * SafetySignalDetector — caía en fallback_chat, dependiendo por completo de
 * que el LLM del chat genérico improvisara una respuesta adecuada, sin
 * ninguna garantía determinista ni el flag de TrainingProfile.safety_status.
 *
 * Este screen corre para TODO mensaje, antes de cualquier clasificación de
 * Intent — exactamente lo que pide el hallazgo: la seguridad no puede
 * depender del routing normal. Si detecta una señal, delega en
 * TrainingHandler::handle(), que YA tenía la lógica correcta de
 * flagging+escalamiento como su primer paso (ver TrainingHandler::handle(),
 * punto 1) — no se duplica esa lógica aquí, solo se garantiza que se
 * alcance sin importar el estado de sesión ni el Intent que el Router
 * habría elegido.
 *
 * Regla que se mantiene (ver docs/DECISIONS.md): el LLM nunca decide que un
 * mensaje es seguro — SafetySignalDetector es determinista (sin llamada a
 * IA), y este screen tampoco llama a ningún proveedor de IA para decidir si
 * intercepta el mensaje.
 */
class SafetySignalPreRoutingScreen implements PreRoutingScreenInterface
{
    public function __construct(
        private readonly SafetySignalDetector $detector,
        private readonly TrainingHandler $trainingHandler,
    ) {}

    public function screen(ExecutionContext $context): bool
    {
        $body = $context->message->messageBody ?? '';

        if ($this->detector->detect($body) === null) {
            return false;
        }

        Log::info('SAFETY_SIGNAL_PRE_ROUTING', [
            'tenant_id' => $context->tenant->id,
            'customer_phone' => $context->message->from,
        ]);

        // TrainingHandler::handle() detecta la señal de nuevo (mismo
        // SafetySignalDetector, misma entrada) y hace el flagging real +
        // responde — es la única fuente de verdad para esa lógica, no se
        // reescribe aquí. La doble detección es una llamada determinista y
        // barata (comparación de substrings, sin IA), no una duplicación de
        // reglas de negocio.
        $this->trainingHandler->handle($context);

        return true;
    }
}
