<?php

namespace App\Jobs;

use App\Core\Messaging\Dispatcher;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Ingest;
use App\Core\Messaging\Router;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Thin queue orchestrator (Hito 2): resolves the inbound message through the
 * Core messaging pipeline (Ingest → Router → Dispatcher → Handler) instead of
 * containing the conversational business logic itself — that now lives in
 * App\Handlers\FallbackChatHandler, reached via the Router/Dispatcher.
 *
 * The constructor signature, middleware() and failed() are unchanged from
 * before the Router was introduced, so WhatsAppController keeps dispatching
 * this Job exactly as it always has.
 */
class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Tenant $tenant,
        public string $from,
        public ?string $messageBody,
        public ?string $phoneId,
        public ?string $messageType = null,
        public ?string $mediaId = null,
        public ?int $productContext = null,
    ) {}

    /**
     * Ensure only one message for a given customer conversation is processed
     * at a time. Without this, two near-simultaneous messages (e.g. the
     * customer double-clicking the same Click-to-WhatsApp ad, or a queue
     * retry racing a still-running attempt) both read the conversation
     * history before either saves its turn — each one replies as if it were
     * the first message, producing duplicate/incoherent answers. Serializing
     * per tenant+phone makes the second job wait and see the updated history.
     *
     * releaseAfter() puts the second job back on the queue to retry shortly
     * instead of dropping it, so it still gets answered — just after the
     * first one finishes.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("whatsapp-message:{$this->tenant->id}:{$this->from}"))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(Ingest $ingest, Router $router, Dispatcher $dispatcher): void
    {
        // Observabilidad (Hito 7): tiempo total "webhook → respuesta" — desde
        // que este Job arranca (el webhook ya respondió 200 a Meta de forma
        // async, esto mide el procesamiento real) hasta que termina de
        // despachar al Handler correspondiente. Ver docs/DECISIONS.md (D021).
        $jobStartedAt = microtime(true);

        try {
            // Verify tenant object is properly serialized
            if (!$this->tenant || !$this->tenant->id) {
                Log::error('CRITICAL: Tenant object is null or missing ID in job', [
                    'has_tenant' => $this->tenant !== null,
                    'tenant_id' => $this->tenant->id ?? 'NULL',
                ]);
                throw new \Exception('Tenant object is not properly initialized in job');
            }

            Log::info("JOB_START: Processing WhatsApp message", [
                'tenant_id' => $this->tenant->id,
                'tenant_name' => $this->tenant->name,
                'customer_phone' => $this->from,
                'message' => $this->messageBody,
                'message_type' => $this->messageType,
                'media_id' => $this->mediaId,
            ]);

            $ingested = $ingest->process(
                $this->tenant,
                $this->from,
                $this->messageBody,
                $this->phoneId,
                $this->messageType,
                $this->mediaId,
            );

            if ($ingested === null) {
                // Ingest already handled everything (bot disabled, or audio
                // transcription could not proceed) — nothing more to do.
                Log::info('JOB_END', [
                    'tenant_id' => $this->tenant->id,
                    'customer_phone' => $this->from,
                    'outcome' => 'ingest_stopped_pipeline',
                    'elapsed_ms' => (int) round((microtime(true) - $jobStartedAt) * 1000),
                ]);

                return;
            }

            // Conversation already exists by the time the Job runs —
            // WhatsAppController::handle() does a firstOrCreate() before
            // dispatching — this is a read, not a write.
            $conversation = Conversation::where('tenant_id', $this->tenant->id)
                ->where('customer_phone', $this->from)
                ->first();

            $context = new ExecutionContext(
                tenant: $this->tenant,
                conversation: $conversation,
                message: $ingested,
                legacy: ['product_context' => $this->productContext],
            );

            $routerStartedAt = microtime(true);
            $intent = $router->route($context);
            Log::info('ROUTER_CLASSIFIED', [
                'tenant_id' => $this->tenant->id,
                'customer_phone' => $this->from,
                'intent' => $intent->value,
                'elapsed_ms' => (int) round((microtime(true) - $routerStartedAt) * 1000),
            ]);

            $dispatcher->dispatch($context, $intent);

            Log::info('JOB_END', [
                'tenant_id' => $this->tenant->id,
                'customer_phone' => $this->from,
                'intent' => $intent->value,
                'outcome' => 'dispatched',
                'elapsed_ms' => (int) round((microtime(true) - $jobStartedAt) * 1000),
            ]);
        } catch (\Exception $e) {
            // Log the specific error with full context
            Log::error('Job: AI Provider Error for tenant: ' . $this->tenant->name, [
                'tenant_id' => $this->tenant->id,
                'tenant_name' => $this->tenant->name,
                'customer_phone' => $this->from,
                'provider' => $this->tenant->ai_provider,
                'error_message' => $e->getMessage(),
                'user_message' => $this->messageBody,
                'attempt' => $this->attempts(),
                'elapsed_ms' => (int) round((microtime(true) - $jobStartedAt) * 1000),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send graceful fallback message to customer only on API failures
            $fallbackMessage = 'Lo siento, estoy experimentando dificultades técnicas. Por favor intenta más tarde.';
            try {
                WhatsAppService::sendMessage($this->from, $fallbackMessage, $this->tenant);
            } catch (\Exception $sendError) {
                Log::error('Failed to send fallback message', [
                    'tenant_id' => $this->tenant->id,
                    'customer_phone' => $this->from,
                    'error' => $sendError->getMessage(),
                ]);
            }

            // Re-throw to mark job as failed after max retries
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job ProcessWhatsAppMessage failed after max retries', [
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'customer_phone' => $this->from,
            'exception' => $exception->getMessage(),
        ]);
    }
}
