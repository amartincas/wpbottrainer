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
use App\Payments\Support\PaymentValidationService;
use App\Payments\Support\ReceiptExtractionService;
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
 *   ¿hay un Payment abierto (pending/under_review)? -> receipt_submission
 *   ¿pregunta por el estado?                          -> payment_status
 *   ¿ya eligió un método (nequi/daviplata)?            -> payment_instructions
 *   en otro caso                                        -> payment_options
 *
 * NUNCA escribe en TrainingAccess — ese es el único trabajo de
 * App\Payments\Support\PaymentConfirmationService, invocado exclusivamente
 * desde la acción de Filament (confirmar/rechazar), nunca desde aquí. La
 * IA (ReceiptExtractionService) solo extrae; PaymentValidationService
 * (determinista) solo marca banderas; el humano decide.
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

        $price = $tenant->monthly_price !== null
            ? number_format((float) $tenant->monthly_price, 0, ',', '.').' '.$tenant->currency
            : 'el precio vigente (te lo confirmamos al elegir un método)';

        $lines = ["💳 Para activar tu servicio, el valor es {$price}. Elige un método:"];

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

    private function handlePaymentInstructions(Contact $contact, array $chosen, string $from, Tenant $tenant): void
    {
        if ($tenant->monthly_price === null) {
            $this->reply($from, 'Este servicio todavía no tiene un precio configurado. Contáctanos para activarlo manualmente.', $tenant);

            return;
        }

        $payment = Payment::create([
            'contact_id' => $contact->id,
            'amount' => $tenant->monthly_price,
            'currency' => $tenant->currency,
            'method' => $chosen['type'],
            'method_label' => $chosen['label'],
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);

        $amount = number_format((float) $tenant->monthly_price, 0, ',', '.').' '.$tenant->currency;
        $instructions = $tenant->payment_instructions
            ? "\n\n{$tenant->payment_instructions}"
            : '';

        $message = "Perfecto, para pagar con {$chosen['label']}:\n"
            ."Número: {$chosen['number']}\n"
            ."Valor: {$amount}{$instructions}\n\n"
            .'Cuando hagas la transferencia, envíame el comprobante (foto o descríbemelo) y lo verificamos. '
            .'Tu acceso se activa solo después de confirmar el pago — te aviso en cuanto quede listo. 💪';

        $this->reply($from, $message, $tenant);

        Log::info('PAYMENT_INSTRUCTIONS_SENT', ['payment_id' => $payment->id, 'tenant_id' => $tenant->id, 'method' => $chosen['label']]);
    }

    // ── Subflujo: receipt_submission ───────────────────────────────────

    private function handleReceiptSubmission(Payment $payment, string $rawBody, ?string $messageType, ?string $mediaId, Tenant $tenant, string $from): void
    {
        $isImage = $messageType === 'image' && $mediaId !== null;
        $downloadedPath = null;

        if ($isImage) {
            $downloadedPath = WhatsAppService::downloadMedia($mediaId, $tenant);

            if ($downloadedPath === null) {
                $this->reply($from, 'No pude descargar esa imagen. ¿Puedes reenviarla o describirme los datos del pago (monto, referencia, fecha)?', $tenant);

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

        $receiptPath = $isImage ? $this->persistReceiptFile($downloadedPath, $tenant) : null;

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
     * Ingest, que se elimina tras transcribir).
     */
    private function persistReceiptFile(string $relativePath, Tenant $tenant): array
    {
        $contents = Storage::disk('local')->get($relativePath);
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $mime = Storage::disk('local')->mimeType($relativePath) ?: 'application/octet-stream';
        $hash = hash('sha256', $contents);

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

    private function emitPaymentAlert(Payment $payment, Tenant $tenant): void
    {
        try {
            $flagsSummary = $payment->validation_flags !== []
                ? "\n⚠️ ".implode(', ', $payment->validation_flags)
                : '';

            $lines = [
                '🚨 Pago pendiente de verificación',
                "Pago #{$payment->id}",
                "Usuario: {$payment->contact->customer_phone}",
                "Método: {$payment->method_label}",
                'Monto: '.number_format((float) $payment->amount, 0, ',', '.').' '.$payment->currency,
                'Fecha: '.now()->format('Y-m-d'),
                'Referencia: '.($payment->extracted_data['reference'] ?? 'no legible'),
                'Estado: pendiente'.$flagsSummary,
            ];

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
