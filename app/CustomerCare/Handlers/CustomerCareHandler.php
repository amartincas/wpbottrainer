<?php

namespace App\CustomerCare\Handlers;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\CustomerCare\Support\CustomerServiceEscalationDetector;
use App\CustomerCare\Support\CustomerServiceRequestRecorder;
use App\CustomerCare\Support\FaqCustomerCareAi;
use App\CustomerCare\Support\FaqMatcher;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Facades\Log;

/**
 * Hito 14 — el único Handler del camino INDEPENDIENTE de FAQ/Customer
 * Service (sin Training activo — ver `Intent::CustomerCare`). El
 * equivalente DURANTE un turno de Training no pasa por aquí — vive dentro
 * del motor de multi-intent de `App\Training` (ver `ConversationTurnResolver`),
 * precisamente para no reemplazar/perder ese contexto.
 *
 * Como máximo una llamada IA por turno: (1) detección determinista de
 * escalación explícita (`CustomerServiceEscalationDetector`, sin IA); si no
 * aplica, (2) `FaqCustomerCareAi::evaluate()` — SIEMPRE se invoca una vez
 * que se llega hasta aquí, incluso con cero candidatos (la IA debe generar
 * el acuse de recibo en el mismo turno, nunca una segunda llamada).
 */
class CustomerCareHandler implements HandlerInterface
{
    public function __construct(
        private readonly CustomerServiceEscalationDetector $csEscalation,
        private readonly FaqMatcher $faqMatcher,
        private readonly FaqCustomerCareAi $faqAi,
        private readonly CustomerServiceRequestRecorder $recorder,
    ) {}

    public function handle(ExecutionContext $context): void
    {
        $tenant = $context->tenant;
        $from = $context->message->from;
        $body = $context->message->messageBody ?? '';

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $from],
            ['summary' => 'Registro de CustomerCare', 'bot_active' => true],
        );

        $this->logInbound($tenant, $from, $body);

        if ($this->csEscalation->detect($body)) {
            $this->recorder->record($contact, $body);
            $this->reply($from, CustomerServiceRequestRecorder::EXPLICIT_REQUEST_TEXT, $tenant);

            return;
        }

        $candidates = $this->faqMatcher->retrieveCandidates($tenant, $body);

        // Única llamada IA del turno — SIEMPRE, incluso con $candidates vacío.
        $result = $this->faqAi->evaluate($body, $candidates, $tenant);
        $result = $this->faqMatcher->sanitize($result, $candidates);

        $faqResponseText = $result['faq_response_text'] ?? null;

        if (is_string($faqResponseText) && trim($faqResponseText) !== '') {
            $this->reply($from, $faqResponseText, $tenant);

            return;
        }

        $this->recorder->record($contact, $body);

        $csMessage = $result['customer_service_message'] ?? null;
        $replyText = is_string($csMessage) && trim($csMessage) !== ''
            ? $csMessage
            : CustomerServiceRequestRecorder::FAQ_FALLBACK_TEXT;

        $this->reply($from, $replyText, $tenant);
    }

    private function logInbound(Tenant $tenant, string $from, string $body): void
    {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'user',
            'content' => $body,
        ]);
    }

    private function reply(string $from, string $text, Tenant $tenant): void
    {
        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'content' => $text,
        ]);

        $wamid = WhatsAppService::sendMessage($from, $text, $tenant);

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($message->id, $wamid);
        } else {
            Log::warning('CUSTOMER_CARE_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
            ]);
        }
    }
}
