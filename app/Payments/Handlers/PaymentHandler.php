<?php

namespace App\Payments\Handlers;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\HandlerInterface;
use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\MembershipPlan;
use App\Payments\Support\PaymentValidationService;
use App\Payments\Support\ReceiptExtractionService;
use Illuminate\Support\Collection;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El único Handler de Payments (Hito 8) — subflujos internos, NUNCA un
 * Handler por método de pago (Nequi/Daviplata/pasarela comparten este
 * mismo Handler, igual que Training resuelve onboarding/reporte dentro de
 * un único TrainingHandler):
 *
 *   ¿hay un Payment abierto SIN membresía elegida? -> plan_selection (Hito 11)
 *   ¿hay un Payment abierto CON membresía elegida? -> receipt_submission
 *   ¿pregunta por el estado?                          -> payment_status
 *   ¿ya eligió un método (nequi/daviplata)?            -> payment_instructions
 *   en otro caso                                        -> payment_options
 *
 * NUNCA escribe en TrainingAccess — ese es el único trabajo de
 * App\Payments\Support\PaymentConfirmationService, invocado exclusivamente
 * desde la acción de Filament (confirmar/rechazar), nunca desde aquí. La
 * IA (ReceiptExtractionService) solo extrae; PaymentValidationService
 * (determinista) solo marca banderas; el humano decide.
 *
 * Hito 11 — un Payment ahora nace en dos pasos: se crea al elegir MÉTODO
 * (como siempre), pero sin `amount`/`currency`/`membership_plan_id` hasta
 * que el usuario elige una membresía (`Payment::needsPlanSelection()`).
 * Reutiliza el ÚNICO mecanismo de estado que este Handler ya tenía ("hay
 * un Payment abierto") — no se agrega ninguna tabla de estado
 * conversacional nueva, solo un chequeo adicional sobre esa misma señal.
 * Nunca se piden instrucciones de pago ni se acepta un comprobante antes
 * de que exista una membresía válida seleccionada.
 */
class PaymentHandler implements HandlerInterface
{
    private const STATUS_KEYWORDS = ['estado', 'cómo va', 'como va', 'ya confirmaron', 'confirmaron mi pago', 'mi pago'];

    public function __construct(
        private readonly ReceiptExtractionService $extractor,
        private readonly PaymentValidationService $validator,
        private readonly AlertService $alerts,
    ) {}

    public function handle(ExecutionContext $context): void
    {
        $tenant = $context->tenant;
        $from = $context->message->from;
        $rawBody = $context->message->messageBody ?? '';
        $messageType = $context->message->messageType;
        $mediaId = $context->message->mediaId;

        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_phone' => $from],
            ['summary' => 'Registro de Payments', 'bot_active' => true],
        );

        $this->logInbound($tenant, $from, $rawBody !== '' ? $rawBody : '[imagen]');

        $openPayment = $contact->payments()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::UnderReview])
            ->latest()
            ->first();

        if ($openPayment !== null && $openPayment->needsPlanSelection()) {
            $this->handlePlanSelection($openPayment, $rawBody, $from, $tenant);

            return;
        }

        if ($openPayment !== null) {
            $this->handleReceiptSubmission($openPayment, $rawBody, $messageType, $mediaId, $tenant, $from);

            return;
        }

        if ($this->looksLikeStatusInquiry($rawBody)) {
            $this->handlePaymentStatus($contact, $from, $tenant);

            return;
        }

        $chosen = $this->detectChosenMethod($rawBody, $tenant);

        if ($chosen !== null) {
            $this->handlePaymentInstructions($contact, $chosen, $from, $tenant);

            return;
        }

        $this->handlePaymentOptions($from, $tenant);
    }

    // ── Subflujo: payment_options ──────────────────────────────────────

    private function handlePaymentOptions(string $from, Tenant $tenant): void
    {
        $methods = $this->availableMethods($tenant);

        if ($methods === []) {
            $this->reply($from, 'Por ahora no hay un método de pago configurado. Contáctanos y te ayudamos a activarlo.', $tenant);

            return;
        }

        // Hito 11: el precio ya no es único por Tenant — depende de la
        // membresía que se elija después de esto (1/3/6/12 meses...), así
        // que no se anticipa ningún monto aquí. El precio real se muestra
        // en handlePlanSelection()/applyPlanSelection(), una vez elegido
        // el método.
        $lines = ['💳 Para activar tu servicio, elige un método de pago:'];

        foreach ($methods as $label) {
            $lines[] = "- {$label}";
        }

        $lines[] = 'Responde con el nombre del método que prefieras.';

        $this->reply($from, implode("\n", $lines), $tenant);
    }

    /**
     * @return array<int, string> nombres de método (method_label) realmente
     *         configurados para este Tenant — nunca hardcodeados.
     */
    private function availableMethods(Tenant $tenant): array
    {
        $methods = [];

        if (! empty($tenant->nequi_number)) {
            $methods[] = 'Nequi';
        }

        if (! empty($tenant->daviplata_number)) {
            $methods[] = 'Daviplata';
        }

        if (! empty($tenant->gateway_provider)) {
            $methods[] = $tenant->gateway_provider;
        }

        return $methods;
    }

    private function detectChosenMethod(string $body, Tenant $tenant): ?array
    {
        $normalized = mb_strtolower($body);

        if (str_contains($normalized, 'nequi') && ! empty($tenant->nequi_number)) {
            return ['label' => 'Nequi', 'number' => $tenant->nequi_number, 'type' => PaymentMethodType::ManualTransfer];
        }

        if (str_contains($normalized, 'daviplata') && ! empty($tenant->daviplata_number)) {
            return ['label' => 'Daviplata', 'number' => $tenant->daviplata_number, 'type' => PaymentMethodType::ManualTransfer];
        }

        return null;
    }

    // ── Subflujo: payment_instructions ─────────────────────────────────

    /**
     * Hito 11: el Payment nace aquí SIN precio/membresía todavía — el
     * método ya se sabe (`$chosen`), pero `amount`/`currency`/
     * `membership_plan_id` quedan nulos hasta elegir una membresía. Si el
     * Tenant solo tiene UNA opción activa, se auto-selecciona de inmediato
     * (mismo comportamiento de fricción que antes de este hito, cuando
     * solo existía un precio); con varias, se pregunta.
     */
    private function handlePaymentInstructions(Contact $contact, array $chosen, string $from, Tenant $tenant): void
    {
        $activePlans = $this->activePlans($tenant);

        if ($activePlans->isEmpty()) {
            $this->reply($from, 'Este servicio todavía no tiene ninguna membresía configurada. Contáctanos para activarlo manualmente.', $tenant);

            return;
        }

        $payment = Payment::create([
            'contact_id' => $contact->id,
            'method' => $chosen['type'],
            'method_label' => $chosen['label'],
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);

        if ($activePlans->count() === 1) {
            $this->applyPlanSelection($payment, $activePlans->first(), $chosen, $tenant, $from);

            return;
        }

        $this->sendPlanOptions($activePlans, $from, $tenant);
    }

    // ── Subflujo: plan_selection (Hito 11) ──────────────────────────────

    /**
     * @return Collection<int, MembershipPlan>
     */
    private function activePlans(Tenant $tenant): Collection
    {
        return MembershipPlan::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('duration_months')
            ->get();
    }

    private function sendPlanOptions(Collection $plans, string $from, Tenant $tenant): void
    {
        $lines = ['Elige la membresía que quieres activar:'];

        foreach ($plans as $plan) {
            $price = number_format((float) $plan->price, 0, ',', '.').' '.$plan->currency;
            $lines[] = "- {$plan->label}: {$price}";
        }

        $lines[] = 'Responde con la que prefieras.';

        $this->reply($from, implode("\n", $lines), $tenant);
    }

    /**
     * Detecta la membresía elegida contra el catálogo ACTIVO del Tenant —
     * un plan `is_active=false` nunca puede seleccionarse porque ni
     * siquiera entra en esta consulta. Sin IA, mismo criterio determinista
     * que detectChosenMethod(): primero por `label` (más específico, ej.
     * "el de 3 meses"), luego por el número de meses solo ("3").
     */
    private function detectChosenPlan(string $body, Collection $activePlans): ?MembershipPlan
    {
        $normalized = mb_strtolower(trim($body));

        foreach ($activePlans as $plan) {
            if (str_contains($normalized, mb_strtolower($plan->label))) {
                return $plan;
            }
        }

        foreach ($activePlans as $plan) {
            if (preg_match('/(?<!\d)'.$plan->duration_months.'(?!\d)/', $normalized) === 1) {
                return $plan;
            }
        }

        return null;
    }

    private function handlePlanSelection(Payment $payment, string $rawBody, string $from, Tenant $tenant): void
    {
        $activePlans = $this->activePlans($tenant);

        if ($activePlans->isEmpty()) {
            $this->reply($from, 'Este servicio todavía no tiene ninguna membresía configurada. Contáctanos para activarlo manualmente.', $tenant);

            return;
        }

        $plan = $this->detectChosenPlan($rawBody, $activePlans);

        if ($plan === null) {
            $this->sendPlanOptions($activePlans, $from, $tenant);

            return;
        }

        $chosen = ['label' => $payment->method_label, 'type' => $payment->method, 'number' => $this->methodNumberFor($payment, $tenant)];

        $this->applyPlanSelection($payment, $plan, $chosen, $tenant, $from);
    }

    private function methodNumberFor(Payment $payment, Tenant $tenant): ?string
    {
        return match ($payment->method) {
            PaymentMethodType::ManualTransfer => $payment->method_label === 'Nequi' ? $tenant->nequi_number : $tenant->daviplata_number,
            default => null,
        };
    }

    /**
     * Congela el snapshot (`membership_plan_id`/`membership_months`/
     * `amount`/`currency`) en el Payment ya existente — a partir de aquí,
     * ese Payment NUNCA vuelve a consultar el MembershipPlan para saber
     * cuánto vale o cuánto dura, ni siquiera si el catálogo cambia después
     * (ver docs/DECISIONS.md). Envía las instrucciones de pago reales —
     * antes de esto, el usuario nunca vio un monto ni un número de cuenta.
     *
     * @param  array{label: string, type: PaymentMethodType, number: ?string}  $chosen
     */
    private function applyPlanSelection(Payment $payment, MembershipPlan $plan, array $chosen, Tenant $tenant, string $from): void
    {
        $payment->update([
            'membership_plan_id' => $plan->id,
            'membership_months' => $plan->duration_months,
            'amount' => $plan->price,
            'currency' => $plan->currency,
        ]);

        $amount = number_format((float) $plan->price, 0, ',', '.').' '.$plan->currency;
        $instructions = $tenant->payment_instructions
            ? "\n\n{$tenant->payment_instructions}"
            : '';
        $numberLine = $chosen['number'] !== null ? "Número: {$chosen['number']}\n" : '';

        $message = "Perfecto, {$plan->label} con {$chosen['label']}:\n"
            .$numberLine
            ."Valor: {$amount}{$instructions}\n\n"
            .'Cuando hagas la transferencia, envíame el comprobante (foto o descríbemelo) y lo verificamos. '
            .'Tu acceso se activa solo después de confirmar el pago — te aviso en cuanto quede listo. 💪';

        $this->reply($from, $message, $tenant);

        Log::info('PAYMENT_INSTRUCTIONS_SENT', [
            'payment_id' => $payment->id, 'tenant_id' => $tenant->id,
            'method' => $chosen['label'], 'membership_plan_id' => $plan->id, 'membership_months' => $plan->duration_months,
        ]);
    }

    // ── Subflujo: receipt_submission ───────────────────────────────────

    private function handleReceiptSubmission(Payment $payment, string $rawBody, ?string $messageType, ?string $mediaId, Tenant $tenant, string $from): void
    {
        $isImage = $messageType === 'image' && $mediaId !== null;
        $downloadedPath = null;
        $hash = null;

        if ($isImage) {
            $downloadedPath = WhatsAppService::downloadMedia($mediaId, $tenant);

            if ($downloadedPath === null) {
                $this->reply($from, 'No pude descargar esa imagen. ¿Puedes reenviarla o describirme los datos del pago (monto, referencia, fecha)?', $tenant);

                return;
            }

            $hash = hash('sha256', Storage::disk('local')->get($downloadedPath));

            // Hito 11 (D3) — el mismo archivo, reenviado con un WAMID
            // distinto (el dedup de Meta en WhatsAppController no aplica
            // aquí, son mensajes genuinamente distintos), no debe volver a
            // gastar una llamada de IA, crear un segundo PaymentReceipt, ni
            // emitir una segunda alerta idéntica al superadmin — se
            // detecta ANTES de extraer nada.
            if (PaymentReceipt::where('payment_id', $payment->id)->where('file_hash', $hash)->exists()) {
                $this->reply($from, 'Ya recibí este comprobante — lo sigo revisando, te aviso apenas quede confirmado. 🙏', $tenant);

                return;
            }
        }

        $extracted = $isImage
            ? $this->extractFromImageFile($downloadedPath, $tenant)
            : $this->extractor->extractFromText($rawBody, $tenant);

        // Nada útil extraído (ni monto ni referencia) — no es un comprobante
        // real todavía, no se crea ningún PaymentReceipt ni se cambia el
        // estado del Payment (mismo criterio que ExecutionReportService
        // cuando un mensaje no trae ninguna señal real de reporte).
        if ($extracted['amount'] === null && $extracted['reference'] === null) {
            $this->reply($from, 'Cuando tengas el comprobante, envíamelo (foto o los datos: monto, referencia y fecha) y lo verifico.', $tenant);

            return;
        }

        $receiptPath = $isImage ? $this->persistReceiptFile($downloadedPath, $tenant, $hash) : null;

        PaymentReceipt::create([
            'payment_id' => $payment->id,
            'file_path' => $receiptPath['path'] ?? null,
            'mime_type' => $receiptPath['mime'] ?? null,
            'file_hash' => $receiptPath['hash'] ?? null,
            'source_type' => $isImage ? 'image' : 'text',
            'extracted_data' => $extracted,
        ]);

        $validationFlags = $this->validator->validate($payment, $extracted);

        $payment->update([
            'status' => PaymentStatus::UnderReview,
            'receipt_submitted_at' => now(),
            'extracted_data' => $extracted,
            'validation_flags' => $validationFlags,
        ]);

        $this->reply($from, 'Recibí tu comprobante — lo estamos verificando. Te aviso apenas quede confirmado, normalmente no toma mucho. 🙏', $tenant);

        $this->emitPaymentAlert($payment, $tenant);
    }

    /**
     * @return array{amount: ?float, date: ?string, time: ?string, reference: ?string, entity: ?string, payer_name: ?string, uncertain: bool}
     */
    private function extractFromImageFile(string $relativePath, Tenant $tenant): array
    {
        $absolutePath = Storage::disk('local')->path($relativePath);
        $mime = Storage::disk('local')->mimeType($relativePath) ?: 'image/jpeg';
        $base64 = base64_encode(file_get_contents($absolutePath));

        return $this->extractor->extractFromImage($base64, $mime, $tenant);
    }

    /**
     * Copia el archivo ya descargado (transitorio, en whatsapp_media/) a
     * una ubicación permanente propia de Payments — un comprobante es
     * evidencia de auditoría, nunca se borra (a diferencia del audio de
     * Ingest, que se elimina tras transcribir). `$hash` ya viene calculado
     * por el chequeo de duplicados (Hito 11, D3) — nunca se recalcula.
     */
    private function persistReceiptFile(string $relativePath, Tenant $tenant, string $hash): array
    {
        $contents = Storage::disk('local')->get($relativePath);
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $mime = Storage::disk('local')->mimeType($relativePath) ?: 'application/octet-stream';

        $destination = "receipts/{$tenant->id}/".Str::uuid().".{$extension}";
        Storage::disk('local')->put($destination, $contents);

        return ['path' => $destination, 'mime' => $mime, 'hash' => $hash];
    }

    // ── Subflujo: payment_status ────────────────────────────────────────

    private function handlePaymentStatus(Contact $contact, string $from, Tenant $tenant): void
    {
        $payment = $contact->payments()->latest()->first();

        if ($payment === null) {
            $this->reply($from, 'Todavía no tienes ningún pago registrado. Escríbeme "quiero pagar" para ver las opciones.', $tenant);

            return;
        }

        $message = match ($payment->status) {
            PaymentStatus::Pending => 'Tu pago está pendiente — todavía no me has enviado el comprobante.',
            PaymentStatus::UnderReview => 'Tu pago está en revisión. Te avisamos en cuanto lo confirmemos.',
            PaymentStatus::Confirmed => '✅ Tu pago fue confirmado el '.$payment->reviewed_at->format('d/m/Y').'. Tu acceso está activo.',
            PaymentStatus::Rejected => '❌ Tu pago fue rechazado. Motivo: '.($payment->review_note ?? 'sin detalle').'. Escríbeme si quieres intentarlo de nuevo.',
            PaymentStatus::Expired => 'Ese intento de pago venció sin comprobante. Escríbeme "quiero pagar" para empezar de nuevo.',
        };

        $this->reply($from, $message, $tenant);
    }

    // ── Utilidades comunes (mismo shape que TrainingHandler) ────────────

    private function looksLikeStatusInquiry(string $body): bool
    {
        $normalized = mb_strtolower($body);

        foreach (self::STATUS_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Traduce cada `validation_flags` (claves técnicas de
     * PaymentValidationService, sin cambios) a una frase legible para el
     * superadmin en WhatsApp — Filament sigue mostrando la clave técnica tal
     * cual (badges de PaymentsTable/PaymentInfolist, sin tocar), esto es
     * exclusivamente para el texto de la alerta.
     */
    private const FLAG_DESCRIPTIONS = [
        'uncertain_extraction' => 'La IA no está segura de los datos extraídos — revisa el comprobante.',
        'amount_unreadable' => 'No se pudo leer el monto del comprobante.',
        'amount_mismatch' => 'El monto detectado no coincide con el esperado.',
        'reference_missing' => 'No se encontró número de referencia.',
        'reference_already_used' => 'Esa referencia ya fue usada en otro pago confirmado.',
        'date_unreadable' => 'Fecha no pudo validarse automáticamente.',
        'stale_receipt' => 'El comprobante parece tener más de 15 días.',
    ];

    private function emitPaymentAlert(Payment $payment, Tenant $tenant): void
    {
        try {
            $extracted = $payment->extracted_data ?? [];

            $lines = [
                '🚨 Pago pendiente de verificación',
                "Pago #{$payment->id}",
                '',
                'DATOS DEL COMPROBANTE',
                'Monto detectado: '.$this->formatAmount($extracted['amount'] ?? null, $payment->currency),
                'Monto esperado: '.$this->formatAmount((float) $payment->amount, $payment->currency),
                // NUNCA sustituir por now() ni ninguna otra fecha inferida —
                // exclusivamente lo que la IA extrajo literalmente, o "no
                // disponible" si no extrajo nada (ver docs/DECISIONS.md).
                'Fecha extraída: '.($extracted['date'] ?? 'no disponible'),
                'Referencia: '.($extracted['reference'] ?? 'no legible'),
                "Método: {$payment->method_label}",
                "Usuario: {$payment->contact->customer_phone}",
            ];

            if ($payment->validation_flags !== []) {
                $lines[] = '';
                $lines[] = '⚠️ ADVERTENCIAS DE VALIDACIÓN';
                foreach ($payment->validation_flags as $flag) {
                    $lines[] = '- '.(self::FLAG_DESCRIPTIONS[$flag] ?? $flag);
                }
            }

            $lines[] = '';
            $lines[] = 'Estado: pendiente de revisión';

            $this->alerts->send(new Alert(
                category: 'payments',
                severity: AlertSeverity::Warning,
                message: implode("\n", $lines),
                context: [
                    'tenant_id' => $tenant->id,
                    'payment_id' => $payment->id,
                    'contact_id' => $payment->contact_id,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('PAYMENT_ALERT_EMIT_FAILED', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    private function formatAmount(?float $amount, string $currency): string
    {
        if ($amount === null) {
            return 'no disponible';
        }

        return number_format($amount, 0, ',', '.').' '.$currency;
    }

    private function logInbound(Tenant $tenant, string $from, string $rawBody): void
    {
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'user',
            'content' => $rawBody,
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
            Log::warning('PAYMENT_META_SEND_FAILED', [
                'whatsapp_message_id' => $message->id,
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
            ]);
        }
    }
}
