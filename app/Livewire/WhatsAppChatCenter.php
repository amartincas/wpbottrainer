<?php

namespace App\Livewire;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;
use Livewire\Component;

class WhatsAppChatCenter extends Component
{
    // Public properties that Livewire maintains between cycles
    public $conversations = [];
    public $messages = [];
    public ?string $selectedPhone = null;
    public ?int $selectedConversationId = null;
    public bool $botActive = true;
    public string $newMessage = ''; // For text input field
    public ?int $filterTenantId = null; // For superuser tenant filtering
    public $tenants = []; // Available tenants for superuser filter
    public ?int $selectedContactId = null; // For JS modal
    public $whatsappTemplates = [];    // List templates
    public $messageStatuses = [];      // Message delivery statuses from cache

    public function mount()
    {
        $this->loadConversations();
        
        // If superuser, load all tenants for the filter dropdown
        if (Auth::user()?->is_super_admin) {
            $this->tenants = Tenant::all();
        }
    }

    public function render()
    {
        // Reload conversations on each render to prevent them from disappearing
        $this->loadConversations();

        return view('livewire.whats-app-chat-center');
    }

    /**
     * Load all conversations (unique customer phones) and messages for selected conversation
     * CRITICAL: Must filter by tenant_id for multi-tenant safety
     * EXCEPTION: Superusers see all tenants (or filtered by $filterTenantId)
     * ORDERS by most recent message for each conversation (newest first)
     */
    public function loadConversations()
    {
        try {
            $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
            
            // Determine which tenant(s) to query
            if ($isSuperAdmin && $this->filterTenantId) {
                // Superuser with filter applied
                $tenantId = $this->filterTenantId;
            } elseif (!$isSuperAdmin) {
                // Regular user - must use their tenant_id
                $tenantId = Auth::user()?->tenant_id;
                
                if (!$tenantId) {
                    Log::warning('loadConversations: tenant_id is null for non-admin user', [
                        'user_id' => Auth::id(),
                        'user_email' => Auth::user()?->email,
                    ]);
                    $this->conversations = [];
                    return;
                }
            } else {
                // Superuser without filter - see all
                $tenantId = null;
            }

            // 1. Load conversations with the date of the last message
            $query = WhatsAppMessage::query();
            
            // Apply tenant filter if needed
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            
            $this->conversations = $query
                ->select('customer_phone')
                ->selectRaw('MAX(created_at) as last_message_at')
                ->groupBy('customer_phone')
                ->orderBy('last_message_at', 'DESC')
                ->get();

            Log::debug('loadConversations: Retrieved conversations', [
                'is_super_admin' => $isSuperAdmin,
                'tenant_id' => $tenantId,
                'filter_tenant_id' => $this->filterTenantId,
                'count' => count($this->conversations),
            ]);

            // 2. If a phone is selected, load its messages
            if ($this->selectedPhone) {
                $messageQuery = WhatsAppMessage::query()
                    ->where('customer_phone', (string) $this->selectedPhone);
                
                // Apply tenant filter if needed
                if ($tenantId) {
                    $messageQuery->where('tenant_id', $tenantId);
                }
                
                $queryMessages = $messageQuery
                    ->orderBy('created_at', 'asc')
                    ->get();
                
                // Only update and dispatch scroll if the count changed
                if (count($queryMessages) !== count($this->messages)) {
                    $this->messages = $queryMessages;
                    $this->dispatch('scroll-down');
                    
                    Log::debug('loadConversations: Messages updated', [
                        'customer_phone' => $this->selectedPhone,
                        'message_count' => count($queryMessages),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('loadConversations Error: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'is_super_admin' => Auth::user()?->is_super_admin,
                'tenant_id' => Auth::user()?->tenant_id,
                'filter_tenant_id' => $this->filterTenantId,
                'selected_phone' => $this->selectedPhone,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Select a conversation and load its details
     * Fetches bot_active status from contacts table (works for both control records and marketing leads)
     * For superusers, determine the tenant_id of the conversation
     */
    public function selectConversation(string $phone): void
    {
        $this->selectedPhone = (string) $phone;
        $this->loadConversations(); // Force message load

        // Fetch message statuses for this conversation
        $this->getMessageStatuses();

        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        
        if ($isSuperAdmin && !$this->filterTenantId) {
            // Superuser without filter - need to find the tenant this conversation belongs to
            $firstMessage = WhatsAppMessage::query()
                ->where('customer_phone', (string) $phone)
                ->first();
            
            if ($firstMessage) {
                $tenantId = $firstMessage->tenant_id;
            }
        } elseif ($this->filterTenantId) {
            // Using filtered tenant
            $tenantId = $this->filterTenantId;
        } else {
            // Regular user
            $tenantId = Auth::user()?->tenant_id;
        }

        if ($tenantId ?? false) {
            // Check if a control record or marketing lead exists for this phone
            $contact = Contact::query()
                ->where('tenant_id', $tenantId)
                ->where('customer_phone', (string) $phone)
                ->first();

            // Store selected conversation id if one exists
            $conversation = Conversation::query()
                ->where('tenant_id', $tenantId)
                ->where('customer_phone', (string) $phone)
                ->first();

            $this->selectedConversationId = $conversation?->id;
            // Fetch bot_active status, default to true if no record exists yet
            $this->botActive = $contact?->bot_active ?? true;
            // Store selected contact ID for JS modal (can be null if no contact record exists yet)
            $this->selectedContactId = $contact?->id;
            // Load WhatsApp templates for this tenant (for manual template sending)
            $this->whatsappTemplates = \App\Models\WhatsAppTemplate::where('tenant_id', $tenantId)->get();
        } else {
            $this->selectedConversationId = null;
        }

        // Important for JavaScript
        $this->loadConversations();
        $this->dispatch('scroll-down');
    }

    /**
     * Send manual message to customer
     * Saves to database and sends via WhatsAppService
     * 
     * IMPORTANT: Human operator messages are saved with role='assistant' (not 'user').
     * This ensures that when the bot re-enables, it can see and maintain context
     * from everything the human operator said during the intervention period.
     * 
     * Message flow:
     * 1. Operator types message → Saved to DB with role='assistant'
     * 2. Message sent to customer via WhatsApp API
     * 3. Customer replies → ProcessWhatsAppMessage job fetches history
     * 4. History includes operator's previous messages (role='assistant')
     * 5. AI engine receives full context including what operator said
     * 6. Bot continues conversation with full context awareness
     */
    public function sendMessage(): void
    {
        if (empty(trim($this->newMessage)) || !$this->selectedPhone) {
            return;
        }

        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        
        // Determine tenant_id for this message
        if ($isSuperAdmin && !$this->filterTenantId) {
            // Superuser without filter - find the tenant this conversation belongs to
            $firstMessage = WhatsAppMessage::query()
                ->where('customer_phone', $this->selectedPhone)
                ->first();
            
            if ($firstMessage) {
                $tenantId = $firstMessage->tenant_id;
            } else {
                Log::warning('sendMessage: Cannot determine tenant for superuser without existing messages');
                return;
            }
        } elseif ($this->filterTenantId) {
            // Using filtered tenant
            $tenantId = $this->filterTenantId;
        } else {
            // Regular user
            $tenantId = Auth::user()?->tenant_id;
        }
        
        if (!$tenantId) {
            Log::warning('sendMessage: No tenant_id available');
            return;
        }

        try {
            // 1. Save message to database (sent by operator)
            // CRITICAL: role='assistant' ensures bot maintains context when re-enabled
            $message = WhatsAppMessage::create([
                'tenant_id' => $tenantId,
                'customer_phone' => $this->selectedPhone,
                'content' => $this->newMessage,
                'role' => 'assistant', // ← Operator message treated as 'assistant' for AI context
            ]);

            // 2. Send via WhatsApp API
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $wamid = WhatsAppService::sendMessage($this->selectedPhone, $this->newMessage, $tenant);
                
                // 3. Track message status if WAMID was returned
                if ($wamid) {
                    WhatsAppStatusTracker::trackMessage($message->id, $wamid);
                    
                    Log::info('Message status tracking initiated', [
                        'db_message_id' => $message->id,
                        'wamid' => $wamid,
                    ]);
                }
                
                Log::info('Manual message sent via WhatsApp', [
                    'tenant_id' => $tenantId,
                    'customer_phone' => $this->selectedPhone,
                    'message_length' => strlen($this->newMessage),
                    'role' => 'assistant',  // Log that this will be in AI context
                    'wamid' => $wamid,
                ]);
            } else {
                Log::warning('sendMessage: Tenant not found', ['tenant_id' => $tenantId]);
            }

            // 4. Clear input and refresh
            $this->newMessage = '';
            $this->loadConversations();
            
            // 5. Scroll down
            $this->dispatch('scroll-down');
        } catch (\Exception $e) {
            Log::error('sendMessage: Failed to send message', [
                'tenant_id' => $tenantId,
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle bot_active toggle changes
     * Creates bot control records in the contacts table to decouple bot control from marketing leads
     * 
     * IMPORTANT: The "contacts" table serves TWO purposes:
     * 1. Bot Control Records: Any phone where operator toggled bot on/off (customer_name = 'Unknown')
     * 2. Marketing Leads: Completed conversations with customer data (customer_name = actual name)
     * 
     * This decoupling allows operators to disable bots for ANY conversation,
     * even if the customer hasn't provided their data yet (not a "lead" in marketing sense).
     */
    public function updatedBotActive(bool $value): void
    {
        if (!$this->selectedPhone || !Auth::user()?->tenant_id) {
            Log::warning('updatedBotActive: Missing phone or tenant_id');
            return;
        }

        try {
            $tenantId = Auth::user()->tenant_id;
            
            // IMPORTANT: Cast value to boolean and log exactly what we're saving
            $botActiveValue = (bool) $value;
            
            Log::info('updatedBotActive: Toggle switch changed', [
                'tenant_id' => $tenantId,
                'customer_phone' => $this->selectedPhone,
                'raw_value' => $value,
                'cast_value' => $botActiveValue,
                'value_type' => gettype($value),
            ]);
            
            // Create or update control record in contacts table
            // If this phone doesn't have a record yet, this creates a "bot control record"
            // identified by customer_name = 'Unknown'
            $contact = Contact::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'customer_phone' => (string) $this->selectedPhone,
                ],
                [
                    'customer_name' => 'Unknown', // Identifies as control record, not a marketing lead
                    'summary' => 'WhatsApp Chat Center',
                    'bot_active' => $botActiveValue,  // EXPLICIT: Pass the cast boolean value
                ]
            );

            Log::info('Bot Active status toggled (control record)', [
                'tenant_id' => $tenantId,
                'customer_phone' => $this->selectedPhone,
                'bot_active_saved' => $contact->bot_active,
                'database_value' => (bool) $contact->bot_active,
            ]);
        } catch (\Exception $e) {
            Log::error('updatedBotActive: Failed to update status', [
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle tenant filter change for superusers
     * Clears selected phone and reloads conversations with new tenant filter
     */
    public function updatedFilterTenantId($value): void
    {
        if (!Auth::user()?->is_super_admin) {
            return;
        }

        $this->filterTenantId = (int) $value ?: null;
        $this->selectedPhone = null;
        $this->messages = [];
        $this->loadConversations();
        
        Log::info('Tenant filter changed by superuser', [
            'user_id' => Auth::id(),
            'filter_tenant_id' => $this->filterTenantId,
        ]);
    }

    public function sendTemplate(int $templateId, array $customValues = [], ?string $externalPhone = null): void
    {
        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        $targetPhone = $this->selectedPhone;
        $isExternalSend = false;

        if (!empty($externalPhone)) {
            $normalized = preg_replace('/\s+/', '', $externalPhone);
            if (!preg_match('/^\+?\d{6,15}$/', $normalized)) {
                Log::warning('sendTemplate: invalid external phone format', ['external_phone' => $externalPhone]);
                $this->dispatch('template-sent-error');
                return;
            }

            $targetPhone = ltrim($normalized, '+');
            $isExternalSend = true;
        }

        if ($isSuperAdmin && !$this->filterTenantId) {
            $tenantId = $targetPhone
                ? WhatsAppMessage::query()->where('customer_phone', $targetPhone)->value('tenant_id')
                : null;
        } elseif ($this->filterTenantId) {
            $tenantId = $this->filterTenantId;
        } else {
            $tenantId = Auth::user()?->tenant_id;
        }

        if (!$tenantId) {
            Log::warning('sendTemplate: tenant_id could not be resolved', ['selected_phone' => $this->selectedPhone, 'external_phone' => $externalPhone]);
            $this->dispatch('template-sent-error');
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->dispatch('template-sent-error');
            return;
        }

        $template = \App\Models\WhatsAppTemplate::where('id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($template->requires_phone_input && !$isExternalSend) {
            Log::warning('sendTemplate: template requires external phone but none was provided', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $targetPhone = $targetPhone ? (string) $targetPhone : null;
        if (!$targetPhone) {
            Log::warning('sendTemplate: target phone is missing', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $contact = Contact::where('customer_phone', $targetPhone)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($isExternalSend) {
            $contact = Contact::firstOrCreate(
                ['tenant_id' => $tenantId, 'customer_phone' => $targetPhone],
                ['customer_name' => 'Unknown', 'summary' => 'Proactive message', 'bot_active' => false]
            );
        }

        $parametersMap = $template->parameters_map ?? [];
        $leadData = [
            'customer_name'  => $contact?->customer_name  ?? '',
            'customer_phone' => $contact?->customer_phone ?? '',
            'product_service_name'   => $contact?->product_service_name   ?? '',
        ];

        Log::info('DEBUG sendTemplate', [
            'selected_phone' => $this->selectedPhone,
            'external_phone' => $externalPhone,
            'target_phone' => $targetPhone,
            'customValues' => $customValues,
            'parametersMap' => $parametersMap,
            'leadData' => $leadData,
        ]);

        $resolvedValues = [];
        foreach ($parametersMap as $position => $fieldKey) {
            $idx = (int) $position - 1;
            $override = $customValues[$idx] ?? null;
            $resolvedValues[$idx] = ($override !== '' && $override !== null)
                ? $override
                : ($leadData[$fieldKey] ?? '');
        }
        ksort($resolvedValues);

        Log::info('DEBUG resolvedValues', ['resolvedValues' => array_values($resolvedValues)]);

        $conversation = Conversation::firstOrCreate([
            'tenant_id' => $tenantId,
            'customer_phone' => $targetPhone,
        ], [
            'last_session_at' => now(),
        ]);

        $this->selectedConversationId = $conversation->id;
        $this->selectedPhone = $targetPhone;

        $sent = WhatsAppService::sendTemplateMessage(
            to:           $targetPhone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    array_values($resolvedValues),
            tenant:       $tenant,
        );

        if ($sent) {
            $renderedBody = $template->body_preview;
            foreach (array_values($resolvedValues) as $index => $value) {
                $placeholder = '{{'. ($index + 1). '}}';
                $renderedBody = str_replace($placeholder, $value, $renderedBody);
            }

            $message = WhatsAppMessage::create([
                'tenant_id'       => $tenantId,
                'customer_phone' => $targetPhone,
                'content'        => $renderedBody,
                'role'           => 'assistant',
            ]);

            // Track message status if WAMID was returned
            if ($sent) {
                WhatsAppStatusTracker::trackMessage($message->id, $sent);
                
                Log::info('Template message status tracking initiated', [
                    'db_message_id' => $message->id,
                    'wamid' => $sent,
                ]);
            }

            Notification::make()
                ->title('Plantilla enviada')
                ->body('El mensaje fue enviado correctamente al cliente.')
                ->success()
                ->send();

            if ($template->is_reengagement && $contact) {
                $contact->update(['status' => 'waiting_customer', 'bot_active' => true]);
            }

            $this->selectConversation($targetPhone);
        } else {
            Notification::make()
                ->title('Error al enviar')
                ->body('Meta no pudo entregar la plantilla. Revisa los logs.')
                ->danger()
                ->send();
        }
    }

    // Este método se llamará desde el script del modal tras un envío exitoso
    #[On('template-sent')] 
    public function handleTemplateSent()
    {
        $this->loadConversations();
        $this->dispatch('scroll-down');
        Log::info('Chat refreshed after template send', ['phone' => $this->selectedPhone]);
    }

    /**
     * Get delivery statuses for all messages in current conversation
     * Called by Livewire polling to fetch current message statuses from cache
     * 
     * @return void - Updates $messageStatuses property
     */
    public function getMessageStatuses(): void
    {
        if (!$this->selectedPhone) {
            $this->messageStatuses = [];
            return;
        }

        // Get all message IDs from the current conversation
        $messageIds = WhatsAppMessage::where('customer_phone', $this->selectedPhone)
            ->pluck('id')
            ->toArray();

        // Fetch all statuses from cache
        $this->messageStatuses = WhatsAppStatusTracker::getMultipleStatuses($messageIds);

        Log::debug('Message statuses fetched', [
            'customer_phone' => $this->selectedPhone,
            'message_count' => count($messageIds),
            'tracked_count' => count($this->messageStatuses),
        ]);
    }

}