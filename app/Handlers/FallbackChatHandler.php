<?php

namespace App\Handlers;

use App\Core\Messaging\HandlerInterface;
use App\Core\Messaging\ExecutionContext;
use App\Factories\AIServiceFactory;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\Inventory\ProductFinderService;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reproduces, unchanged, the general-purpose free-form sales conversation
 * behaviour that used to live directly inside ProcessWhatsAppMessage before
 * the Router (Hito 2) was introduced: product catalog context, conversation
 * history, system prompt construction, the AI call, lead/contact extraction,
 * image tag processing, message persistence and outbound delivery.
 *
 * This class intentionally contains domain-specific logic (product catalog,
 * lead extraction) that must NOT live in Core — Core only knows this Handler
 * exists and how to invoke it, never what it does.
 *
 * Stateless by design: tenant/from/message data are local variables inside
 * handle() and the private helpers it calls, never instance properties —
 * safe to resolve as a singleton or reuse across messages/tenants.
 */
class FallbackChatHandler implements HandlerInterface
{
    public function handle(ExecutionContext $context): void
    {
        $tenant = $context->tenant;
        $message = $context->message;
        $from = $message->from;
        $messageBody = $message->messageBody;

        // Legacy, FallbackChatHandler-specific concern: a Click-to-WhatsApp ad
        // may have already resolved a specific product for this conversation
        // (see WhatsAppController::resolveTenantFromPayload / ProductFinderService).
        // Core has no idea what this value means — it is just forwarded through
        // the opaque $context->legacy bag. See docs/DECISIONS.md (D016).
        //
        // Note: $context->conversation is also available (resolved once by the
        // Job) but this method still resolves its own Conversation internally,
        // exactly as it did before Hito 3 — left untouched deliberately so this
        // legacy sticky-product logic is not modified, only its entry point.
        $productContextId = $context->legacy['product_context'] ?? null;

        // Retrieve product context at the beginning
        $productContext = $this->getProductContextWithTypes($tenant, $from, $messageBody, $productContextId);

        // Fetch the last 10 messages for this customer
        // IMPORTANT: This includes BOTH AI responses AND human operator messages
        // Both are stored with role='assistant' in the whats_app_messages table:
        // - AI-generated responses: role='assistant', created by this job
        // - Human operator responses: role='assistant', created by sendMessage() in WhatsAppChatCenter
        //
        // This ensures the AI maintains full conversation context even after human intervention.
        $rawHistory = WhatsAppMessage::where('tenant_id', $tenant->id)
            ->where('customer_phone', $from)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'role', 'content', 'created_at']);

        $history = $rawHistory
            ->reverse()
            ->map(function (WhatsAppMessage $msg) {
                return [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            })
            ->toArray();

        // DEBUG: Log the conversation history being sent to AI
        Log::debug('CONVERSATION_HISTORY: Fetched for AI context', [
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'total_messages_in_history' => count($history),
            'messages' => array_map(function ($msg) {
                return [
                    'role' => $msg['role'],
                    'preview' => substr($msg['content'], 0, 50) . (strlen($msg['content']) > 50 ? '...' : ''),
                ];
            }, $history),
        ]);

        // ===== SYSTEM PROMPT PIPELINE =====
        // Pure concatenation of database values following: Tenant Instructions → Product Context → Metadata

        // 1. Load tenant system prompt (must be set by tenant admin)
        $systemPrompt = trim($tenant->system_prompt ?? '');

        // 2. Validate tenant prompt exists and log if missing
        if (empty($systemPrompt)) {
            Log::warning('PROMPT_VALIDATION: Tenant system_prompt is empty', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ]);
            // Minimal fallback: does not replace user's instructions, just signals empty
            $systemPrompt = 'You are an assistant.';
        }

        // 3. Append product context (formatted with headers, no hardcoded rules)
        if ($productContext && !empty($productContext['context'])) {
            $systemPrompt .= "\n\n### PRODUCT CATALOG DATA:\n" . $productContext['context'];
        } else {
            // Log when no product context available
            Log::warning('CONTEXT_VALIDATION: No product context retrieved', [
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'message' => substr($messageBody, 0, 100),
            ]);
        }

        // 4. Append system metadata (timestamps and completion signal)
        $systemPrompt .= "\n\n### SYSTEM METADATA:\n";
        $systemPrompt .= "Current Date/Time: " . now()->format('Y-m-d H:i:s') . "\n";
        $systemPrompt .= "Lead Completion Signal: [LEAD_COMPLETE]\n";
        $systemPrompt .= "When the customer has confirmed a purchase, an order, or provided enough information to create a lead, append the exact token [LEAD_COMPLETE] at the end of your response. Do not use any other variation of that token.\n";

        // Get the configured AI service for this tenant
        $aiEngine = AIServiceFactory::make($tenant);

        // Debug: Log the final prompt being sent to OpenAI
        Log::info("PROMPT_PIPELINE: System prompt constructed", [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'has_system_prompt' => !empty($tenant->system_prompt),
            'has_product_context' => $productContext !== null && !empty($productContext['context']),
            'prompt_length' => strlen($systemPrompt),
            'full_prompt' => $systemPrompt,
        ]);

        // Get AI response with chat history
        $aiResponse = $aiEngine->getResponse($messageBody, $systemPrompt, $history);

        $messageToSend = $aiResponse;
        $hasLeadToken = strpos($aiResponse, '[LEAD_COMPLETE]') !== false;

        // Extract lead data from the conversation for both explicit and fallback detection
        $leadData = $this->extractLeadDataWithAI($tenant, $from, $messageBody, $productContextId, $history, $aiResponse);
        $shouldCreateLead = $this->shouldCreateLeadFromResponse($aiResponse, $leadData, $hasLeadToken);

        if ($shouldCreateLead) {
            if ($hasLeadToken) {
                $messageToSend = preg_replace('/\[LEAD_COMPLETE\]/', '', $aiResponse);
                $messageToSend = trim($messageToSend);
            }

            Log::info('Contact data extracted', [
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'extracted_data' => $leadData,
                'has_lead_token' => $hasLeadToken,
            ]);

            // Guard against duplicate leads: once an order is confirmed, the AI
            // keeps summarizing it ("Producto: ... Nombre: ... ¡Gracias por tu
            // compra!") on unrelated follow-ups like "¿me avisas cuando llegue?" —
            // and re-emits [LEAD_COMPLETE] each time, since from its perspective
            // it's still describing a confirmed purchase. Without this check,
            // every such follow-up created a brand new Contact row for the same order.
            $recentDuplicateContact = Contact::where('tenant_id', $tenant->id)
                ->where('customer_phone', $from)
                ->where('created_at', '>=', now()->subHour())
                ->exists();

            if ($recentDuplicateContact) {
                Log::warning('DUPLICATE_LEAD_SKIPPED: Ya existe un lead reciente para esta conversación', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                ]);
            } else {
                Contact::create([
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'customer_name' => $leadData['customer_name'] ?? null,
                    'delivery_address_or_location' => $leadData['delivery_address_or_location'] ?? null,
                    'product_service_name' => $leadData['product_service_name'] ?? null,
                    'preferred_date_time' => $leadData['preferred_date_time'] ?? null,
                    'summary' => $messageToSend,
                    'is_processed' => false,
                ]);

                Log::info('Contact created from WhatsApp conversation', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'customer_name' => $leadData['customer_name'] ?? null,
                    'product_service_name' => $leadData['product_service_name'] ?? null,
                    'completion_method' => $hasLeadToken ? 'explicit_token' : 'heuristic_fallback',
                ]);
            }
        }

        // Process AI response to extract and send images
        // This also cleans the message by removing [IMG: id] tags
        $messageToSend = WhatsAppService::processAIResponse(
            $messageToSend,
            $tenant,
            $from
        );

        // Save user message to database
        WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'user',
            'content' => $messageBody,
        ]);

        // Save AI response to database
        $aiMessage = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'content' => $aiResponse,
        ]);

        // Send AI response back to customer (without the [LEAD_COMPLETE] tag)
        $wamid = WhatsAppService::sendMessage($from, $messageToSend, $tenant);

        // Track message status if WAMID was returned
        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($aiMessage->id, $wamid);

            Log::info('AI message status tracking initiated', [
                'db_message_id' => $aiMessage->id,
                'wamid' => $wamid,
            ]);
        }

        Log::info('WhatsApp message processed successfully by job', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'customer_phone' => $from,
            'message_body' => $messageBody,
            'wamid' => $wamid,
        ]);
    }

    /**
     * Get product context information with type details for the message.
     * Returns array with context string and type flags.
     * Includes fallback logic to ensure products are found.
     *
     * @return array|null
     */
    private function getProductContextWithTypes(Tenant $tenant, string $from, ?string $messageBody, ?int $productContextId): ?array
    {
        try {
            Log::info("CONTEXT_RETRIEVAL: Starting product context retrieval", [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'message' => $messageBody,
                'has_product_context_param' => $productContextId !== null,
            ]);

            // Conversation-level memory of which product this customer is
            // already talking about. Without this, a short reply with no
            // product name in it (e.g. "sí", "me parece bien", a name, an
            // address) can't be matched to anything specific, and every such
            // turn falls back to the FULL catalog — mixing unrelated
            // products' (possibly contradictory) sales strategies into the
            // prompt mid-negotiation, which is how the bot ends up pivoting
            // to a product the customer never asked about.
            $conversation = Conversation::where('tenant_id', $tenant->id)
                ->where('customer_phone', $from)
                ->first();

            // If a specific product context was provided, fetch that product
            if ($productContextId) {
                $product = Product::with('images')->find($productContextId);
                if ($product) {
                    Log::info("CONTEXT_RETRIEVAL: Found product by ID", [
                        'product_id' => $productContextId,
                        'product_name' => $product->name,
                    ]);

                    $conversation?->update(['current_product_id' => $product->id]);

                    return [
                        'context' => $this->formatProductData($product),
                        'hasServices' => $product->type === 'service',
                        'hasProducts' => $product->type === 'product',
                    ];
                }
            }

            // Otherwise, search for products mentioned in the message
            Log::info("CONTEXT_RETRIEVAL: Searching for products by message", [
                'tenant_id' => $tenant->id,
                'message' => $messageBody,
            ]);

            $finder = new ProductFinderService();
            $result = $finder->findProductsWithTypes($messageBody, $tenant->id, 10);

            // Ensure images are loaded for the found products
            $result['products']->load('images');

            Log::info("CONTEXT_RETRIEVAL: Search result", [
                'products_count' => $result['products']->count(),
                'has_services' => $result['hasServices'],
                'has_products' => $result['hasProducts'],
                'context_preview' => substr($result['context'], 0, 150),
            ]);

            if ($result['products']->count() === 1) {
                // Confident single-product match this turn (mentioned by name,
                // or the tenant simply has one product) — this becomes (or stays)
                // the conversation's product.
                $conversation?->update(['current_product_id' => $result['products']->first()->id]);
            } elseif ($conversation?->current_product_id) {
                // No confident single match this turn (generic message, empty
                // search, or the multi-product catalog fallback) — stick to the
                // product already established for this conversation instead of
                // mixing in unrelated products on every short reply.
                $stickyProduct = Product::with('images')->find($conversation->current_product_id);

                if ($stickyProduct) {
                    Log::info("CONTEXT_RETRIEVAL: Using sticky conversation product", [
                        'tenant_id' => $tenant->id,
                        'customer_phone' => $from,
                        'product_id' => $stickyProduct->id,
                        'product_name' => $stickyProduct->name,
                    ]);

                    return [
                        'context' => $this->formatProductData($stickyProduct),
                        'hasServices' => $stickyProduct->type === 'service',
                        'hasProducts' => $stickyProduct->type === 'product',
                        'products' => collect([$stickyProduct]),
                    ];
                }
            }

            // If search returned nothing at all, force fetch full catalog
            if ($result['products']->isEmpty()) {
                Log::warning("CONTEXT_RETRIEVAL: Search returned no products, forcing full catalog fetch", [
                    'tenant_id' => $tenant->id,
                    'original_message' => $messageBody,
                ]);

                // Force fetch all products for this tenant (ProductFinderService already handles this fallback)
                // But let's add an explicit secondary fallback
                $allProducts = Product::where('tenant_id', $tenant->id)
                    ->with('images')
                    ->limit(10)
                    ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

                Log::info("CONTEXT_RETRIEVAL: Explicit full catalog fetch", [
                    'tenant_id' => $tenant->id,
                    'products_found' => $allProducts->count(),
                ]);

                if ($allProducts->isEmpty()) {
                    Log::warning("CONTEXT_RETRIEVAL: No products exist in database for tenant", [
                        'tenant_id' => $tenant->id,
                    ]);

                    return null;
                }

                // Return the explicit full catalog
                $hasServices = $allProducts->where('type', 'service')->isNotEmpty();
                $hasProducts = $allProducts->where('type', 'product')->isNotEmpty();

                Log::info("CONTEXT_CONTENT: " . $this->formatProductsForContext($allProducts));

                return [
                    'context' => $this->formatProductsForContext($allProducts),
                    'hasServices' => $hasServices,
                    'hasProducts' => $hasProducts,
                    'products' => $allProducts,
                ];
            }

            Log::info("CONTEXT_CONTENT: " . ($result['context'] ?? 'EMPTY'));

            return [
                'context' => $result['context'],
                'hasServices' => $result['hasServices'],
                'hasProducts' => $result['hasProducts'],
                'products' => $result['products'],
            ];
        } catch (\Exception $e) {
            Log::warning('Product context retrieval failed', [
                'tenant_id' => $tenant->id,
                'product_id' => $productContextId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Format products list for context injection.
     * Pure data pipeline: validates fields exist, formats with headers, concatenates database values only.
     * NO hardcoded instructions or sales text - all content from database.
     */
    private function formatProductsForContext(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "No products available.";
        }

        $formatted = "Available Offerings:\n\n";

        foreach ($products as $product) {
            // ===== PRODUCT IDENTITY =====
            $formatted .= "Product: " . ($product->name ?? 'Unknown') . "\n";
            $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";

            // ===== PRICING & AVAILABILITY =====
            $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";

            if ($product->type === 'service') {
                $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
                $formatted .= "Availability: " . $availability . "\n";
            } else {
                $stock = $product->stock ?? 0;
                $formatted .= "Stock: " . $stock . " units\n";
            }

            // ===== DESCRIPTION =====
            if (!empty($product->description)) {
                $formatted .= "Description: " . $product->description . "\n";
            }

            // ===== IMAGES =====
            if ($product->images && $product->images->count() > 0) {
                $formatted .= "Images:\n";
                // Sort images: primary first, then by ID
                $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
                foreach ($sortedImages as $image) {
                    $formatted .= "- [IMG:{$image->id}] Product image\n";
                }
            } else {
                $formatted .= "Images: None\n";
            }

            // ===== DATABASE FIELDS FOR AI SALES & RULES =====
            // These are populated by tenant admin in database - passed through without modification

            if (!empty($product->ai_sales_strategy)) {
                $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
            }

            if (!empty($product->faq_context)) {
                $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
            }

            if (!empty($product->required_customer_info)) {
                $formatted .= "Required Data: " . $product->required_customer_info . "\n";
            } else {
                Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ]);
            }

            $formatted .= "\n---\n\n";
        }

        return $formatted;
    }

    /**
     * Get product context information for the message (legacy method).
     * If a specific product ID is provided, fetch that product's details.
     * Otherwise, search for product mentions in the user message.
     *
     * @deprecated Kept as reference only, mirrors the pre-Router behaviour.
     */
    private function getProductContext(Tenant $tenant, string $from, ?string $messageBody, ?int $productContextId): ?string
    {
        $contextData = $this->getProductContextWithTypes($tenant, $from, $messageBody, $productContextId);
        return $contextData['context'] ?? null;
    }

    /**
     * Format a single product's data for context.
     * Pure data pipeline: validates fields, formats with headers, no hardcoded sales text.
     */
    private function formatProductData(Product $product): string
    {
        // ===== PRODUCT IDENTITY =====
        $formatted = "Product: " . ($product->name ?? 'Unknown') . "\n";
        $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";

        // ===== PRICING & AVAILABILITY =====
        $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";

        if ($product->type === 'service') {
            $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
            $formatted .= "Availability: " . $availability . "\n";
        } else {
            $stock = $product->stock ?? 0;
            $formatted .= "Stock: " . $stock . " units\n";
        }

        // ===== DESCRIPTION =====
        if (!empty($product->description)) {
            $formatted .= "Description: " . $product->description . "\n";
        }

        // ===== IMAGES =====
        if ($product->images && $product->images->count() > 0) {
            $formatted .= "Images:\n";
            // Sort images: primary first, then by ID
            $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
            foreach ($sortedImages as $image) {
                $formatted .= "- [IMG:{$image->id}] Product image\n";
            }
        } else {
            $formatted .= "Images: None\n";
        }

        // ===== DATABASE FIELDS FOR AI SALES & RULES =====
        // Tenant admin manages these in database - passed through without modification

        if (!empty($product->ai_sales_strategy)) {
            $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
        }

        if (!empty($product->faq_context)) {
            $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
        }

        if (!empty($product->required_customer_info)) {
            $formatted .= "Required Data: " . $product->required_customer_info . "\n";
        } else {
            Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);
        }

        return $formatted;
    }

    /**
     * Extract lead data using AI with strict context validation.
     * Uses JSON extraction to ensure accurate product/service matching.
     *
     * CRITICAL: This method prioritizes the CURRENT conversation context,
     * ignoring legacy values from earlier in history if the topic has changed.
     *
     * @param array $history Chat history
     * @param string $lastAiResponse The last AI response before [LEAD_COMPLETE]
     * @return array Extracted lead data
     */
    private function extractLeadDataWithAI(Tenant $tenant, string $from, ?string $messageBody, ?int $productContextId, array $history, string $lastAiResponse): array
    {
        $leadData = [
            'customer_name' => null,
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
        ];

        try {
            // Build extraction prompt focusing on CURRENT context
            $extractionPrompt = <<<'PROMPT'
You are a data extraction specialist. Extract customer information from the conversation.

CRITICAL RULES FOR EXTRACTION:
1. Only extract the product/service the customer EXPLICITLY confirmed or requested in the MOST RECENT message
2. IGNORE any products mentioned earlier if the customer changed their mind or topic
3. The product/service must be mentioned in either:
   - The last customer message, OR
   - The last AI response (where you confirmed their request)
4. Do NOT use products from the beginning of the conversation if they were discussing a different service later

Return ONLY valid JSON (no markdown, no code blocks, no extra text):
{
  "customer_name": "extracted name or null",
  "delivery_address_or_location": "address or null",
  "product_service_name": "CURRENT confirmed service only - not from earlier in conversation",
  "preferred_date_time": "date/time or null"
}

CONVERSATION:
PROMPT;

            // Add relevant conversation context (last few messages are most important)
            $contextMessages = array_slice($history, -6); // Last 6 messages for context
            foreach ($contextMessages as $msg) {
                $role = ucfirst($msg['role']);
                $extractionPrompt .= "{$role}: {$msg['content']}\n";
            }

            // Add current message
            $extractionPrompt .= "Customer: {$messageBody}\n";
            $extractionPrompt .= "\nAI Confirmation: {$lastAiResponse}\n";

            // Get AI to extract as JSON
            $aiEngine = AIServiceFactory::make($tenant);
            $jsonResponse = $aiEngine->getResponse(
                "Extract lead information as JSON",
                $extractionPrompt,
                [] // No history for this extraction request
            );

            // Log raw response for debugging
            Log::info('Raw AI Contact Extraction Response', [
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'raw_response' => substr($jsonResponse, 0, 500), // First 500 chars
            ]);

            // Parse JSON response
            $jsonResponse = trim($jsonResponse);

            // Remove markdown code blocks if present
            $jsonResponse = preg_replace('/^```(?:json)?\s*/i', '', $jsonResponse);
            $jsonResponse = preg_replace('/\s*```$/', '', $jsonResponse);
            $jsonResponse = trim($jsonResponse);

            $extracted = json_decode($jsonResponse, true);

            if ($extracted && is_array($extracted)) {
                // Validate product/service name against recent messages
                if (!empty($extracted['product_service_name'])) {
                    $productName = $extracted['product_service_name'];
                    $recentText = $messageBody . ' ' . $lastAiResponse;

                    // Check if product appears in recent context (case-insensitive)
                    if (stripos($recentText, $productName) === false) {
                        Log::warning('Extracted product not in recent context', [
                            'extracted_product' => $productName,
                            'recent_text' => substr($recentText, 0, 200),
                        ]);

                        // Fall back to regex extraction which is more conservative
                        $fallbackData = $this->extractLeadDataRegex($tenant, $from, $messageBody, $productContextId);
                        $extracted['product_service_name'] = $fallbackData['product_service_name'];
                    }
                }

                // Sanitize and validate each field
                $leadData['customer_name'] = $this->sanitizeString($extracted['customer_name'] ?? null, 100);
                $leadData['delivery_address_or_location'] = $this->sanitizeString($extracted['delivery_address_or_location'] ?? null, 255);
                $leadData['product_service_name'] = $this->sanitizeString($extracted['product_service_name'] ?? null, 150);
                $leadData['preferred_date_time'] = $this->sanitizeString($extracted['preferred_date_time'] ?? null, 150);

                Log::info('Contact Data Successfully Extracted via AI', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'customer_name' => $leadData['customer_name'],
                    'product_service_name' => $leadData['product_service_name'],
                ]);
            } else {
                Log::warning('Failed to parse AI extraction JSON', [
                    'tenant_id' => $tenant->id,
                    'response' => substr($jsonResponse, 0, 200),
                ]);

                // Fall back to regex extraction
                $leadData = $this->extractLeadDataRegex($tenant, $from, $messageBody, $productContextId);
            }
        } catch (\Exception $e) {
            Log::error('AI extraction failed, using fallback regex', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);

            // Fall back to regex extraction
            $leadData = $this->extractLeadDataRegex($tenant, $from, $messageBody, $productContextId);
        }

        return $leadData;
    }

    /**
     * Decide whether the current turn should create a Contact record.
     */
    private function shouldCreateLeadFromResponse(string $aiResponse, array $leadData, bool $hasLeadToken): bool
    {
        if ($hasLeadToken) {
            return true;
        }

        if (empty($leadData['product_service_name']) && empty($leadData['customer_name'])) {
            return false;
        }

        if ($this->isLeadCompletionResponse($aiResponse)) {
            return true;
        }

        return false;
    }

    private function isLeadCompletionResponse(string $aiResponse): bool
    {
        $text = mb_strtolower($aiResponse);
        $patterns = [
            'orden está confirmada',
            'pedido está confirmado',
            'tu orden está confirmada',
            'tu pedido está confirmado',
            'su pedido está confirmado',
            'su orden está confirmada',
            'pedido confirmado',
            'orden confirmada',
            'confirmo el pedido',
            'confirmé el pedido',
            'su pedido está casi listo',
            'su orden está casi lista',
            'orden lista',
            'pedido listo',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize and validate string fields
     */
    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        // Remove "null" string if present
        if (strtolower($value) === 'null') {
            return null;
        }

        if (strlen($value) === 0) {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * Fallback regex-based lead extraction
     * Used when AI extraction fails or when validation indicates it picked up legacy data
     */
    private function extractLeadDataRegex(Tenant $tenant, string $from, ?string $messageBody, ?int $productContextId): array
    {
        $leadData = [
            'customer_name' => null,
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
        ];

        // Combine recent user messages only (not full history)
        $recentMessages = array_slice($this->getRawHistory($tenant, $from), -3); // Last 3 messages
        $allUserMessages = '';
        foreach ($recentMessages as $message) {
            if ($message['role'] === 'user') {
                $allUserMessages .= ' ' . $message['content'];
            }
        }
        $allUserMessages .= ' ' . $messageBody;

        // Extract customer name
        if (preg_match('/(?:my name is|i\'?m|call me|my name\'?s)\s+([A-Za-z\s]+?)(?:[,\.]|$|\b(?:and|for|at|on))/i', $allUserMessages, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) < 50) {
                $leadData['customer_name'] = $name;
            }
        }

        // Extract address
        if (preg_match('/(?:address|location|at|deliver to|service at|located at)\s+([^,\.]*[,\.]|\b[A-Za-z0-9\s,]+(?:Road|Street|Ave|Boulevard|Lane|Drive|Court|District|City|Apt|Apartment|Suite|Block)[\w\s]*)/i', $allUserMessages, $matches)) {
            $address = trim($matches[1]);
            if (strlen($address) < 200) {
                $leadData['delivery_address_or_location'] = preg_replace('/[,\.]+$/', '', $address);
            }
        }

        // Extract product/service - ONLY from current context, strict matching
        $productContext = $this->getProductContextWithTypes($tenant, $from, $messageBody, $productContextId);
        if ($productContext && !empty($productContext['products'])) {
            // Find the first product actually mentioned in RECENT messages
            foreach ($productContext['products'] as $product) {
                if (stripos($allUserMessages, $product->name) !== false) {
                    $leadData['product_service_name'] = $product->name;
                    break; // Stop at first match
                }
            }
        }

        // Extract date/time
        if (preg_match('/(?:date|time|when|schedule|book|appointment)\s+(?:for|at|on|:)?\s*([^,\.]*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}[^,\.]*|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^,\.]*|(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))[^,\.]*)/i', $allUserMessages, $matches)) {
            $datetime = trim($matches[1]);
            if (strlen($datetime) < 100) {
                $leadData['preferred_date_time'] = $datetime;
            }
        }

        return $leadData;
    }

    /**
     * Get raw message history (helper for extraction)
     */
    private function getRawHistory(Tenant $tenant, string $from): array
    {
        return WhatsAppMessage::where('tenant_id', $tenant->id)
            ->where('customer_phone', $from)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->toArray();
    }
}
