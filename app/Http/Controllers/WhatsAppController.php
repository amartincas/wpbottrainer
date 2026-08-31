<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Conversation;
use App\Jobs\ProcessWhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Services\Inventory\ProductFinderService;
use App\Models\Contact;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class WhatsAppController extends Controller
{
    /**
     * Handle Meta WhatsApp webhook verification challenge.
     * GET /api/whatsapp/webhook/{tenant_token}
     *
     * Meta sends a GET request with query parameters:
     * - hub.mode=subscribe
     * - hub.challenge=<challenge_string>
     * - hub.verify_token=<verify_token>
     *
     * @param Request $request
     * @param string $tenant_token
     * @return Response
     */
    public function verify(Request $request, string $tenant_token)
    {
        // Find tenant by decrypted wa_verify_token
        // Since wa_verify_token is encrypted in DB, we load all tenants and compare
        // (Tenant table is small, so this is efficient)
        $tenant = Tenant::all()->firstWhere('wa_verify_token', $tenant_token);

        if (!$tenant) {
            Log::warning('WhatsApp webhook verification failed: tenant not found', [
                'token_length' => strlen($tenant_token),
            ]);
            return response('Tenant Not Found', 404);
        }

        // Capturamos los datos que envía Meta
        $mode = $request->input('hub_mode');
        $challenge = $request->input('hub_challenge');
        $verifyToken = $request->input('hub_verify_token');

        // Validamos contra el token de la base de datos
        if ($mode === 'subscribe' && $verifyToken === $tenant->wa_verify_token) {
            // IMPORTANTE: Retornar solo el challenge como texto plano
            return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming WhatsApp messages from Meta webhook.
     * POST /api/whatsapp/webhook/{tenant_token}
     *
     * @param Request $request
     * @param string $tenant_token
     * @return Response
     */
    public function handle(Request $request, string $tenant_token): Response
{
    $payload = $request->json()->all();

    // 1. PROCESS STATUS EVENTS (Sent, Delivered, Read, Failed)
    if (isset($payload['entry'][0]['changes'][0]['value']['statuses'])) {
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'] ?? [];
        foreach ($statuses as $statusEvent) {
            $wamid = $statusEvent['id'] ?? null;
            $status = $statusEvent['status'] ?? null;
            
            if ($wamid && $status) {
                // Update message status in cache for frontend display
                WhatsAppStatusTracker::updateStatus($wamid, $status);
                
                Log::info('WhatsApp message status received', [
                    'wamid' => $wamid,
                    'status' => $status,
                    'timestamp' => $statusEvent['timestamp'] ?? null,
                ]);
            }
        }
        return response('OK', 200);
    }

    // Extract first message from the array
    $messages = $payload['entry'][0]['changes'][0]['value']['messages'] ?? [];
    if (empty($messages)) {
        return response('OK', 200);
    }

    $message = $messages[0];
    $phoneId = $message['id'] ?? null; // Este es el WAMID único de Meta

    // 🔥 CONTROL DE IDEMPOTENCIA: Bloquear reintentos de Meta de inmediato
    //
    // IMPORTANTE: Cache::add() es atómico (check-and-set en una sola operación).
    // Antes esto era un Cache::has() seguido de un Cache::put() por separado —
    // dos peticiones casi simultáneas para el mismo WAMID (reintento de Meta
    // procesado por otro worker antes de que el primero alcanzara a escribir
    // la caché) podían pasar ambas el chequeo y disparar el job dos veces,
    // generando dos respuestas de IA para el mismo mensaje del cliente.
    if ($phoneId) {
        $cacheKey = "whatsapp_msg_processed:{$phoneId}";

        // add() devuelve false si la clave ya existía — ahí sabemos que es un reintento.
        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
            Log::warning('WhatsApp Webhook: Reintento de Meta detectado e ignorado.', ['message_id' => $phoneId]);
            return response('EVENT_RECEIVED', 200);
        }
    }

    // Si pasa los filtros, guardamos el log real del mensaje entrante
    Log::info('Raw WhatsApp Webhook Payload', ['payload' => $payload]);

    $tenant = $this->resolveTenantFromPayload($payload);
    if (!$tenant) {
        Log::warning('WhatsApp message handling failed: unable to resolve tenant from webhook metadata', [
            'tenant_token' => $tenant_token,
            'payload_metadata' => data_get($payload, 'entry.0.changes.0.value.metadata'),
        ]);
        return response('Not Found', 404);
    }

    $type = $message['type'] ?? null;
    $fromPhone = $message['from'] ?? null;

    $body = null;
    $mediaId = null;

    if ($type === 'text') {
        $body = $message['text']['body'] ?? null;
    } elseif (in_array($type, ['audio', 'voice'], true)) {
        $mediaId = $message[$type]['id'] ?? null;
        
        // Keep body empty so ProcessWhatsAppMessage can trigger transcription logic
        $body = null; 
        
        Log::info('WhatsApp audio/voice message received', [
            'tenant_id' => $tenant->id,
            'customer_phone' => $fromPhone,
            'message_id' => $phoneId,
            'media_id' => $mediaId,
        ]);
    }

    if (!$fromPhone || (!$body && !$mediaId)) {
        return response('OK', 200);
    }

    Log::info("CONTENIDO REAL: " . $body);

    // Find or create conversation
    $conversation = Conversation::firstOrCreate(
        ['tenant_id' => $tenant->id, 'customer_phone' => $fromPhone],
        ['last_session_at' => now()]
    );

    if ($conversation->wasRecentlyCreated === false) {
        $conversation->update(['last_session_at' => now()]);
    }

    // Si el cliente escribió desde un anuncio de Click-to-WhatsApp, Meta manda
    // el ID del anuncio en referral.source_id. Lo usamos para identificar el
    // producto exacto sin depender de que el mensaje prellenado del anuncio
    // coincida con el nombre del producto; si no hay match, ProcessWhatsAppMessage
    // cae de vuelta a la búsqueda por texto del mensaje.
    $adId = $message['referral']['source_id'] ?? null;
    $productContext = $adId
        ? (new ProductFinderService())->findProductByAdId($adId, $tenant->id)?->id
        : null;

    // Dispatch job to process the message asynchronously
    ProcessWhatsAppMessage::dispatch(
        $tenant,
        $fromPhone,
        $body,
        $phoneId,
        $type,
        $mediaId,
        $productContext
    );

    Log::info('WhatsApp message queued for processing', [
        'tenant_id' => $tenant->id,
        'message_id' => $phoneId,
    ]);

    return response('EVENT_RECEIVED', 200);
}
// -------------------------------------------------------------------------
    // NEW METHOD
    // -------------------------------------------------------------------------

    /**
     * Send a Meta-approved template message to a contact from the operator dashboard.
     *
     * POST /api/whatsapp/templates/send
     *
     * Request body:
     * {
     *   "contact_id":   123,
     *   "template_id":  7,
     *   "custom_values": ["value1", "value2"]   // operator-supplied overrides
     * }
     *
     * Flow:
     *  1. Validate request fields.
     *  2. Load contact and template, enforcing tenant_id ownership on both.
     *  3. Auto-prefill variables from contact data using parameters_map,
     *     then merge/override with any custom_values supplied by the operator.
     *  4. Call WhatsAppService::sendTemplateMessage().
     *  5. If template is_reengagement, reset the contact status so the
     *     AIOrchestrator can resume once the customer replies.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function sendManualTemplate(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Derive tenant from the authenticated user
        $tenantId = $user->tenant_id;    

        // ── 1. Validate ───────────────────────────────────────────────────────
        $validated = $request->validate([
            'contact_id'        => ['required', 'integer', 'exists:contacts,id'],
            'template_id'    => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'custom_values'  => ['sometimes', 'array'],
            'custom_values.*' => ['string', 'max:1024'],
        ]);

        // ── 2. Load & authorise ───────────────────────────────────────────────
        // The authenticated user's tenant_id gates both records, ensuring a tenant
        // operator can never send a template to a contact from another tenant.
        $contact = Contact::findOrFail($validated['contact_id']);

        /** @var \App\Models\Tenant $tenant */
        $tenant = $contact->tenant;   // eager via relationship

        // Verify the template belongs to the same tenant as the contact.
        $template = WhatsAppTemplate::where('id', $validated['template_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$template) {
            Log::warning('WhatsApp template send unauthorised: template does not belong to contact tenant', [
                'tenant_id'    => $tenant->id,
                'contact_id'     => $contact->id,
                'template_id' => $validated['template_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Template not found or does not belong to this tenant.',
            ], 403);
        }

        // ── 3. Build variables array ──────────────────────────────────────────
        // parameters_map defines which contact/product fields auto-fill each position.
        // Example map: {"1": "customer_name", "2": "product_service_name", "3": "tracking_number"}
        //
        // Supported auto-fill keys (extend as your Contact / Product models grow):
        //   customer_name, customer_phone, product_service_name, contact_status
        //
        // custom_values supplied by the operator act as an indexed override list:
        //   position 1 → custom_values[0], position 2 → custom_values[1], …
        // An operator value that is a non-empty string takes precedence over the
        // auto-filled value for that position.

        $parametersMap  = $template->parameters_map ?? [];  // ["1" => "customer_name", …]
        $customValues   = $validated['custom_values'] ?? [];
        $resolvedValues = [];

        // Auto-fill lookup table — add more mappings here as needed
        $contactData = [
            'customer_name'        => $contact->customer_name        ?? '',
            'customer_phone'       => $contact->customer_phone       ?? '',
            'product_service_name' => $contact->product_service_name ?? '',
            'contact_status'       => $contact->status                ?? '',
        ];

        // Walk through each positional slot defined in the map
        foreach ($parametersMap as $position => $fieldKey) {
            $positionIndex = (int) $position - 1;  // convert 1-based to 0-based

            // Operator override wins if provided and non-empty
            $operatorValue = $customValues[$positionIndex] ?? null;

            $resolvedValues[$positionIndex] = (is_string($operatorValue) && $operatorValue !== '')
                ? $operatorValue
                : ($contactData[$fieldKey] ?? '');
        }

        // Fill any extra positions the operator added beyond the map definition
        foreach ($customValues as $idx => $value) {
            if (!isset($resolvedValues[$idx]) && $value !== '') {
                $resolvedValues[$idx] = $value;
            }
        }

        // Ensure sequential numeric keys for the service method
        ksort($resolvedValues);
        $finalVariables = array_values($resolvedValues);

        Log::info('WhatsApp manual template send initiated', [
            'tenant_id'      => $tenant->id,
            'contact_id'       => $contact->id,
            'template_id'   => $template->id,
            'template_name' => $template->name,
            'is_reengagement' => $template->is_reengagement,
            'variable_count' => count($finalVariables),
        ]);

        // ── 4. Send via WhatsAppService ───────────────────────────────────────
        $sent = WhatsAppService::sendTemplateMessage(
            to:           $contact->customer_phone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    $finalVariables,
            tenant:       $tenant,
        );

        if (!$sent) {
            Log::error('WhatsApp manual template send failed', [
                'tenant_id'      => $tenant->id,
                'contact_id'       => $contact->id,
                'template_name' => $template->name,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send template message. Check logs for Meta API details.',
            ], 502);
        }

        // ── 5. Re-engagement: reset contact status ───────────────────────────────
        // When an operator sends a re-engagement template (is_reengagement = true),
        // the 24-hour WhatsApp conversation window will reopen once the customer
        // replies. We pre-emptively set the contact back to an active state so the
        // AIOrchestrator (via ProcessWhatsAppMessage) will resume AI processing
        // as soon as that reply arrives — without requiring manual intervention.
        if ($template->is_reengagement) {
            $contact->update(['status' => 'waiting_customer']);

            // Also re-enable the bot for this phone in case it was paused
            // (bot_active flag lives on the leads table per ARCHITECTURE.md §2.2 Step 2)
            $contact->update(['bot_active' => true]);

            Log::info('WhatsApp re-engagement template sent: contact status reset', [
                'tenant_id'    => $tenant->id,
                'contact_id'     => $contact->id,
                'customer'    => $contact->customer_phone,
                'new_status'  => 'waiting_customer',
                'bot_active'  => true,
            ]);
        }

        Log::info('WhatsApp manual template send completed successfully', [
            'tenant_id'        => $tenant->id,
            'contact_id'         => $contact->id,
            'template_name'   => $template->name,
            'is_reengagement' => $template->is_reengagement,
        ]);

        return response()->json([
            'success'         => true,
            'message'         => 'Template message sent successfully.',
            'is_reengagement' => $template->is_reengagement,
            'lead_status'     => $template->is_reengagement ? 'waiting_customer' : $contact->status,
        ]);
    }

    private function resolveTenantFromPayload(array $payload): ?Tenant
    {
        $metadata = data_get($payload, 'entry.0.changes.0.value.metadata', []);
        $phoneNumberId = $metadata['phone_number_id'] ?? $metadata['phoneNumberId'] ?? null;

        if (empty($phoneNumberId)) {
            return null;
        }

        return Tenant::where('wa_phone_number_id', (string) $phoneNumberId)->first();
    }
}
