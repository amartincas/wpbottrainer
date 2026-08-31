<?php

namespace App\Core\Messaging;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\AI\OpenAIService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns the raw inputs of an inbound WhatsApp message into a normalized
 * IngestedMessage the Router can classify — or stops the pipeline early
 * (returns null) when there is nothing left for the Router/Handler to do.
 *
 * This is a Core mechanism: it only knows about generic multi-tenant
 * automation control (a Contact's bot_active flag) and about turning audio
 * into text. It has ZERO knowledge of products, leads, prompts, or any other
 * domain concept — those live in Handlers (see App\Handlers\FallbackChatHandler),
 * which is where this exact logic lived before Hito 2.
 */
class Ingest
{
    /**
     * @return IngestedMessage|null Null means the pipeline is already
     *                               finished (bot disabled, or transcription
     *                               could not proceed) — the caller should
     *                               simply stop, not treat it as an error.
     */
    public function process(
        Tenant $tenant,
        string $from,
        ?string $messageBody,
        ?string $phoneId,
        ?string $messageType,
        ?string $mediaId,
    ): ?IngestedMessage {
        // ===== HUMAN INTERVENTION MODE CHECK (FIRST THING) =====
        // CRITICAL: This check must happen BEFORE ANY AI PROCESSING
        //
        // IMPORTANT: The "contacts" table serves TWO purposes:
        // 1. Bot Control Records: Any phone where operator toggled bot on/off
        // 2. Marketing Leads: Completed conversations with customer data
        //
        // This check decouples bot control from lead status:
        // - If NO contact record exists → Bot continues (default behavior)
        // - If contact exists AND bot_active = false → Bot disabled for human intervention
        // - If contact exists AND bot_active = true → Bot continues
        $botDisabled = Contact::where('tenant_id', $tenant->id)
            ->where('customer_phone', $from)
            ->where('bot_active', false)
            ->exists();

        if ($botDisabled) {
            Log::warning('BOT_DISABLED: Skipping AI response', [
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'message' => $messageBody,
            ]);

            // Still save the user message for reference
            WhatsAppMessage::create([
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'role' => 'user',
                'content' => $messageBody,
            ]);

            return null;
        }
        // ===== END HUMAN INTERVENTION MODE CHECK =====

        if (in_array($messageType, ['audio', 'voice'], true) && empty($messageBody)) {
            Log::info('Audio/voice message detected, starting transcription workflow', [
                'tenant_id' => $tenant->id,
                'customer_phone' => $from,
                'message_type' => $messageType,
                'media_id' => $mediaId,
            ]);

            if (!$mediaId) {
                Log::warning('Audio message missing media_id, cannot transcribe', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'message_type' => $messageType,
                ]);
                return null;
            }

            $localPath = WhatsAppService::downloadMedia($mediaId, $tenant);
            if (!$localPath) {
                Log::error('Failed to download audio media for transcription', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'media_id' => $mediaId,
                ]);
                return null;
            }

            $transcriptionStartedAt = microtime(true);

            try {
                $openAi = new OpenAIService($tenant->ai_api_key, 'whisper-1');
                $transcribedText = $openAi->transcribeAudio(Storage::disk('local')->path($localPath));
                $messageBody = "🎤 [AUDIO]: " . trim($transcribedText);

                if (empty($messageBody)) {
                    throw new \Exception('Transcription returned empty text');
                }

                Log::info('Audio transcription completed successfully', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'media_id' => $mediaId,
                    'transcription_preview' => substr($messageBody, 0, 200),
                    'elapsed_ms' => (int) round((microtime(true) - $transcriptionStartedAt) * 1000),
                ]);
            } catch (\Exception $e) {
                Log::error('Audio transcription failed', [
                    'tenant_id' => $tenant->id,
                    'customer_phone' => $from,
                    'media_id' => $mediaId,
                    'error' => $e->getMessage(),
                    'elapsed_ms' => (int) round((microtime(true) - $transcriptionStartedAt) * 1000),
                    'trace' => $e->getTraceAsString(),
                ]);

                WhatsAppService::sendMessage(
                    $from,
                    'No pude transcribir tu audio. Por favor intenta de nuevo o escríbeme tu pregunta.',
                    $tenant
                );

                return null;
            } finally {
                if (Storage::disk('local')->exists($localPath)) {
                    Storage::disk('local')->delete($localPath);
                }
            }
        }

        return new IngestedMessage($from, $messageBody, $phoneId, $messageType, $mediaId);
    }
}
