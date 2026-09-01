<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a message via WhatsApp Business API.
     *
     * @param string $to Phone number in format: countrycode[phonenumber]
     * @param string $message Message text to send
     * @param Tenant $tenant Tenant with WhatsApp credentials
     * @return string|null WAMID (Meta's message ID) on success, null on failure
     */
    public static function sendMessage(string $to, string $message, Tenant $tenant): ?string
    {
        try {
            $url = "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages";

            $response = Http::withToken($tenant->wa_access_token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]);
            
            if ($response->failed()) {
                Log::error("Error de Meta API", [
                    'tenant_id' => $tenant->id,
                    'status' => $response->status(),
                    'body' => $response->json()
                ]);
            }    

            Log::debug('WhatsApp message sent', [
                'tenant_id' => $tenant->id,
                'to' => $to,
                'status' => $response->status(),
                'success' => $response->successful(),
            ]);

            if (!$response->successful()) {
                Log::warning('WhatsApp message send failed', [
                    'tenant_id' => $tenant->id,
                    'to' => $to,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                return null;
            }

            // Extract WAMID from Meta's response
            $wamid = data_get($response->json(), 'messages.0.id');
            
            Log::info('WhatsApp message sent successfully', [
                'tenant_id' => $tenant->id,
                'to' => $to,
                'wamid' => $wamid,
            ]);

            return $wamid;
        } catch (\Exception $e) {
            Log::error('WhatsApp message send error', [
                'tenant_id' => $tenant->id,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send a Meta-approved HSM Template Message via WhatsApp Business Cloud API.
     *
     * Builds the `template` payload with a `body` component whose positional
     * parameters are constructed from the ordered $variables array.
     *
     * Meta payload reference:
     * POST https://graph.facebook.com/v20.0/{phone_number_id}/messages
     * {
     *   "messaging_product": "whatsapp",
     *   "to": "<phone>",
     *   "type": "template",
     *   "template": {
     *     "name": "<template_name>",
     *     "language": { "code": "<language_code>" },
     *     "components": [
     *       {
     *         "type": "body",
     *         "parameters": [
     *           { "type": "text", "text": "value1" },
     *           { "type": "text", "text": "value2" }
     *         ]
     *       }
     *     ]
     *   }
     * }
     *
     * @param string  $to           Recipient phone (E.164, e.g. "573001234567")
     * @param string  $templateName Technical name registered in Meta Business Manager
     * @param string  $languageCode BCP-47 code, e.g. "es_CO", "en_US"
     * @param array   $variables    Ordered list of replacement values for {{1}}, {{2}}, …
     * @param Tenant   $tenant        Tenant instance carrying wa_access_token & wa_phone_number_id
     * @return string|null          WAMID (Meta's message ID) on success, null on failure
     */
    public static function sendTemplateMessage(
        string $to,
        string $templateName,
        string $languageCode,
        array  $variables,
        Tenant  $tenant
    ): ?string {
        try {
            $url = "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages";

            // Build the ordered parameter objects required by the Meta Cloud API.
            // Each entry in $variables maps to one positional placeholder: {{1}}, {{2}}, …
            $parameters = array_map(
                fn (string $value): array => ['type' => 'text', 'text' => $value],
                array_values($variables)   // ensure sequential numeric keys
            );

            // Only include the components key when there are actual variables.
            // Sending an empty components array causes a Meta API validation error.
            $templatePayload = [
                'name'     => $templateName,
                'language' => ['code' => $languageCode],
            ];

            if (!empty($parameters)) {
                $templatePayload['components'] = [
                    [
                        'type'       => 'body',
                        'parameters' => $parameters,
                    ],
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'template',
                'template'          => $templatePayload,
            ];

            Log::info('WhatsApp template message dispatching', [
                'tenant_id'      => $tenant->id,
                'to'            => $to,
                'template_name' => $templateName,
                'language'      => $languageCode,
                'variable_count' => count($variables),
            ]);

            Log::debug('WhatsApp full payload', [
                'url' => $url,
                'payload' => $payload
            ]);

            $response = Http::withToken($tenant->wa_access_token)
                ->timeout(15)
                ->post($url, $payload);

            // Log detailed Meta error before the generic failure check,
            // mirroring the pattern used in sendMessage().
            if ($response->failed()) {
                Log::error('WhatsApp template message Meta API error', [
                    'tenant_id'      => $tenant->id,
                    'to'            => $to,
                    'template_name' => $templateName,
                    'status'        => $response->status(),
                    'body'          => $response->json(),
                ]);
            }

            if (!$response->successful()) {
                Log::warning('WhatsApp template message send failed', [
                    'tenant_id'      => $tenant->id,
                    'to'            => $to,
                    'template_name' => $templateName,
                    'status'        => $response->status(),
                    'error'         => $response->json(),
                ]);
                return null;
            }

            // Extract WAMID from Meta's response
            $wamid = data_get($response->json(), 'messages.0.id');

            Log::info('WhatsApp template message sent successfully', [
                'tenant_id'      => $tenant->id,
                'to'            => $to,
                'template_name' => $templateName,
                'wamid'         => $wamid,
            ]);

            return $wamid;

        } catch (\Exception $e) {
            Log::error('WhatsApp template message send exception', [
                'tenant_id'      => $tenant->id,
                'to'            => $to,
                'template_name' => $templateName,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Test WhatsApp connection with provided credentials
     *
     * @param string $phoneNumberId WhatsApp Phone Number ID
     * @param string $accessToken WhatsApp Business API access token
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public static function testConnection(string $phoneNumberId, string $accessToken): array
    {
        try {
            $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}";

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'Connection successful! Phone number: ' . ($data['display_phone_number'] ?? 'Unknown'),
                    'data' => $data,
                ];
            }

            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? 'Unknown error from Meta API';

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $errorMessage,
                'data' => $errorData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Download WhatsApp media for transcription.
     *
     * @param string $mediaId WhatsApp media ID
     * @param Tenant $tenant Tenant with WhatsApp credentials
     * @param string|null $mimeType Optional mime type to help with extension
     * @return string|null Relative disk path of downloaded file or null on failure
     */
    public static function downloadMedia(string $mediaId, Tenant $tenant, ?string $mimeType = null): ?string
    {
        try {
            $urlResponse = Http::withToken($tenant->wa_access_token)
                ->timeout(30)
                ->get("https://graph.facebook.com/v20.0/{$mediaId}");

            if ($urlResponse->failed()) {
                Log::error('Failed to retrieve WhatsApp media URL', [
                    'tenant_id' => $tenant->id,
                    'media_id' => $mediaId,
                    'status' => $urlResponse->status(),
                    'body' => $urlResponse->body(),
                ]);
                return null;
            }

            $urlData = $urlResponse->json();
            $downloadUrl = $urlData['url'] ?? null;

            if (!$downloadUrl) {
                Log::error('WhatsApp media URL missing from response', [
                    'tenant_id' => $tenant->id,
                    'media_id' => $mediaId,
                    'response' => $urlData,
                ]);
                return null;
            }

            $mediaResponse = Http::withToken($tenant->wa_access_token)
                ->timeout(60)
                ->get($downloadUrl);

            if ($mediaResponse->failed()) {
                Log::error('Failed to download WhatsApp media content', [
                    'tenant_id' => $tenant->id,
                    'media_id' => $mediaId,
                    'status' => $mediaResponse->status(),
                    'body' => $mediaResponse->body(),
                ]);
                return null;
            }

            $contentType = $mediaResponse->header('Content-Type') ?: $mimeType;
            $extension = 'ogg';
            if ($contentType) {
                if (str_contains($contentType, 'mpeg') || str_contains($contentType, 'mp3')) {
                    $extension = 'mp3';
                } elseif (str_contains($contentType, 'mp4') || str_contains($contentType, 'm4a')) {
                    $extension = 'm4a';
                } elseif (str_contains($contentType, 'ogg')) {
                    $extension = 'ogg';
                } elseif (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
                    // Hito 8 (Payments): comprobantes llegan como imagen, no
                    // audio — sin esto, un JPEG se guardaba con extensión
                    // .ogg por el default de arriba (el contenido seguía
                    // siendo correcto, pero la extensión engañaba).
                    $extension = 'jpg';
                } elseif (str_contains($contentType, 'png')) {
                    $extension = 'png';
                } elseif (str_contains($contentType, 'webp')) {
                    $extension = 'webp';
                }
            }

            $relativePath = "whatsapp_media/{$mediaId}.{$extension}";
            $stored = \Illuminate\Support\Facades\Storage::disk('local')->put($relativePath, $mediaResponse->body());

            if (!$stored) {
                Log::error('Failed to save WhatsApp media to disk', [
                    'tenant_id' => $tenant->id,
                    'media_id' => $mediaId,
                    'relative_path' => $relativePath,
                ]);
                return null;
            }

            Log::info('WhatsApp media downloaded successfully', [
                'tenant_id' => $tenant->id,
                'media_id' => $mediaId,
                'relative_path' => $relativePath,
            ]);

            return $relativePath;
        } catch (\Exception $e) {
            Log::error('Error downloading WhatsApp media', [
                'tenant_id' => $tenant->id,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Send a welcome message after successful setup
     *
     * @param Tenant $tenant Tenant with WhatsApp credentials
     * @param string|null $toNumber Optional target phone number (if null, just logs)
     * @return bool True if message was sent or logged successfully
     */
    public static function sendWelcomeMessage(Tenant $tenant, ?string $toNumber = null): bool
    {
        try {
            $message = sprintf(
                "¡Hola! 🚀 Soy el asistente de %s. Estoy configurado correctamente y listo para atender a tus clientes. ¡Hagamos crecer tu negocio!",
                $tenant->name
            );

            // If target number is provided, send the message
            if ($toNumber) {
                return self::sendMessage($toNumber, $message, $tenant);
            }

            // Otherwise, just log it for the store owner to see
            Log::info('Tenant setup completed - Welcome message ready', [
                'tenant_id' => $tenant->id,
                'store_name' => $tenant->name,
                'message' => $message,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error preparing welcome message', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send an image via WhatsApp Business API.
     *
     * @param string $toNumber Phone number in format: countrycode[phonenumber]
     * @param string $imageUrl Full URL to the image (public accessible)
     * @param Tenant $tenant Tenant with WhatsApp credentials
     * @param string|null $caption Optional caption for the image
     * @return bool True if image was sent successfully
     */
    public static function sendWhatsAppImage(
        string $toNumber,
        string $imageUrl,
        Tenant $tenant,
        ?string $caption = null
    ): bool {
        try {
            $url = "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages";

            $imagePayload = [
                'link' => $imageUrl,
            ];

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toNumber,
                'type' => 'image',
                'image' => $imagePayload,
            ];

            // Add caption if provided (appears as text above image)
            if ($caption) {
                $payload['image']['caption'] = $caption;
            }

            $response = Http::withToken($tenant->wa_access_token)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('WhatsApp image send failed', [
                    'tenant_id' => $tenant->id,
                    'to' => $toNumber,
                    'image_url' => $imageUrl,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                return false;
            }

            Log::debug('WhatsApp image sent', [
                'tenant_id' => $tenant->id,
                'to' => $toNumber,
                'image_url' => $imageUrl,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp image send error', [
                'tenant_id' => $tenant->id,
                'to' => $toNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a video via WhatsApp Business API.
     *
     * Mirrors sendWhatsAppImage() exactly — same payload shape, `type: 'video'`
     * instead of `'image'`. Added in Hito 5 for Training's exercise videos
     * (Exercise.video_url) — no assumption is made about where that URL is
     * ultimately hosted (see docs/DECISIONS.md), only that WhatsApp Cloud API
     * needs a publicly reachable HTTPS URL, same requirement as images.
     *
     * @param string $toNumber Phone number in format: countrycode[phonenumber]
     * @param string $videoUrl Full URL to the video (public accessible)
     * @param Tenant $tenant Tenant with WhatsApp credentials
     * @param string|null $caption Optional caption for the video
     * @return bool True if the video was sent successfully
     */
    public static function sendWhatsAppVideo(
        string $toNumber,
        string $videoUrl,
        Tenant $tenant,
        ?string $caption = null
    ): bool {
        try {
            $url = "https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}/messages";

            $videoPayload = [
                'link' => $videoUrl,
            ];

            if ($caption) {
                $videoPayload['caption'] = $caption;
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toNumber,
                'type' => 'video',
                'video' => $videoPayload,
            ];

            $response = Http::withToken($tenant->wa_access_token)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('WhatsApp video send failed', [
                    'tenant_id' => $tenant->id,
                    'to' => $toNumber,
                    'video_url' => $videoUrl,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                return false;
            }

            Log::debug('WhatsApp video sent', [
                'tenant_id' => $tenant->id,
                'to' => $toNumber,
                'video_url' => $videoUrl,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp video send error', [
                'tenant_id' => $tenant->id,
                'to' => $toNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Process AI response to extract image tags and send images.
     *
     * This function finds all [IMG: product_id] tags in the response,
     * sends the corresponding images via WhatsApp, and returns the cleaned text.
     *
     * @param string $responseText Raw AI response containing potential [IMG: id] tags
     * @param Tenant $tenant Tenant with WhatsApp credentials and products
     * @param string $customerNumber Customer's phone number to send images to
     * @return string Cleaned response text without [IMG: ...] tags
     */
    public static function processAIResponse(
        string $responseText,
        Tenant $tenant,
        string $customerNumber
    ): string {
        try {
            // Find all [IMG: id] tags
            $pattern = '/\[IMG:\s*(\d+)\s*\]/i';
            preg_match_all($pattern, $responseText, $matches);

            Log::info('AI Response Image Processing', [
                'tenant_id' => $tenant->id,
                'customer_number' => $customerNumber,
                'response_text' => $responseText,
                'found_img_tags' => $matches[1] ?? [],
            ]);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $imageId) {
                    Log::info('Processing image tag', [
                        'tenant_id' => $tenant->id,
                        'image_id' => $imageId,
                        'customer_number' => $customerNumber,
                    ]);

                    $image = ProductImage::find($imageId);

                    if ($image) {
                        Log::info('ProductImage found', [
                            'tenant_id' => $tenant->id,
                            'image_id' => $imageId,
                            'image_path' => $image->image_path,
                            'public_url' => $image->public_url,
                            'product_id' => $image->product_id,
                        ]);

                        // Get product name for caption
                        $productName = $image->product ? $image->product->name : 'Product Image';

                        Log::info('Sending image', [
                            'tenant_id' => $tenant->id,
                            'image_id' => $imageId,
                            'public_url' => $image->public_url,
                            'customer_number' => $customerNumber,
                            'product_name' => $productName,
                        ]);

                        // Send the image
                        $imageSent = self::sendWhatsAppImage(
                            $customerNumber,
                            $image->public_url,
                            $tenant,
                            $productName
                        );

                        Log::info('Image send result', [
                            'tenant_id' => $tenant->id,
                            'image_id' => $imageId,
                            'image_sent' => $imageSent,
                            'customer_number' => $customerNumber,
                        ]);
                    } else {
                        Log::warning('ProductImage not found for AI response', [
                            'tenant_id' => $tenant->id,
                            'image_id' => $imageId,
                        ]);
                    }
                }
            } else {
                Log::info('No image tags found in AI response', [
                    'tenant_id' => $tenant->id,
                    'customer_number' => $customerNumber,
                ]);
            }

            // Remove all [IMG: ...] tags from response
            $cleanText = preg_replace($pattern, '', $responseText);

            // Clean up extra whitespace
            $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));

            Log::info('AI Response processing completed', [
                'tenant_id' => $tenant->id,
                'customer_number' => $customerNumber,
                'original_length' => strlen($responseText),
                'cleaned_length' => strlen($cleanText),
            ]);

            return $cleanText;
        } catch (\Exception $e) {
            Log::error('Error processing AI response images', [
                'tenant_id' => $tenant->id,
                'customer_number' => $customerNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return original text if processing fails
            return $responseText;
        }
    }
}
