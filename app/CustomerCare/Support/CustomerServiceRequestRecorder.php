<?php

namespace App\CustomerCare\Support;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\CustomerCare\Models\CustomerServiceRequest;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * Hito 14 — la ÚNICA puerta para registrar una solicitud de atención
 * humana. `CustomerServiceRequest` es la fuente de verdad; `AlertLog`
 * (vía `AlertService`, reutilizado sin cambios) sigue siendo exclusivamente
 * infraestructura de notificación — nunca se usa como fuente de verdad ni
 * se le agrega ningún propósito nuevo (ver docs/DECISIONS.md).
 *
 * `message` persistido es SIEMPRE el mensaje original del usuario — nunca
 * el texto de acuse de recibo (fijo o redactado por IA) que se le responde.
 */
class CustomerServiceRequestRecorder
{
    /**
     * Petición EXPLÍCITA de ayuda humana (detectada por
     * `CustomerServiceEscalationDetector`, sin IA) — texto fijo, nunca
     * depende de la IA ni inventa tiempos de respuesta.
     */
    public const EXPLICIT_REQUEST_TEXT = 'Hemos recibido tu solicitud de atención. '
        .'Un miembro de nuestro equipo la revisará y te responderá por este mismo medio.';

    /**
     * Respaldo determinista para el fallback de "ninguna FAQ responde" —
     * se usa únicamente cuando la IA no devuelve un `customer_service_message`
     * válido, o cuando `FaqMatcher::sanitize()` descarta la salida de la IA
     * por un `faq_match_id` inválido. También es el ejemplo de tono que se
     * le muestra a la IA en el prompt — una sola fuente para ambos usos.
     */
    public const FAQ_FALLBACK_TEXT = 'No tengo información suficiente para responderte con precisión. '
        .'Ya estoy consultando esta pregunta con nuestro equipo para darte una respuesta correcta.';

    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    public function record(Contact $contact, string $message): CustomerServiceRequest
    {
        $request = CustomerServiceRequest::create([
            'contact_id' => $contact->id,
            'message' => $message,
        ]);

        $this->emitAlert($request, $contact, $message);

        return $request;
    }

    private function emitAlert(CustomerServiceRequest $request, Contact $contact, string $message): void
    {
        try {
            $this->alerts->send(new Alert(
                category: 'customer_service',
                severity: AlertSeverity::Warning,
                message: "🆘 Solicitud de atención al cliente\n"
                    .'Cliente: '.($contact->customer_name ?? 'Sin nombre')."\n"
                    ."WhatsApp: {$contact->customer_phone}\n"
                    ."Mensaje: {$message}\n"
                    .'Fecha: '.now()->format('d/m/Y H:i'),
                context: [
                    'tenant_id' => $contact->tenant_id,
                    'customer_service_request_id' => $request->id,
                    'contact_id' => $contact->id,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('CUSTOMER_SERVICE_ALERT_EMIT_FAILED', [
                'customer_service_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
