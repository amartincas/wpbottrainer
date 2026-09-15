<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controles P0 de lanzamiento — verificación de `X-Hub-Signature-256` de
 * Meta sobre el webhook de WhatsApp. Se aplica ÚNICAMENTE a la ruta POST
 * (`WhatsAppController::handle()`) — la ruta GET de verificación/challenge
 * (`WhatsAppController::verify()`) nunca lleva este header, Meta no lo envía
 * ahí, y por eso este middleware nunca se registra en esa ruta.
 *
 * El secreto (`config('services.meta.app_secret')`) es GLOBAL para toda la
 * plataforma, no por tenant — decisión explícita de esta implementación
 * (ver auditoría pre-implementación). La firma SIEMPRE se calcula sobre
 * `$request->getContent()` (los bytes crudos exactos que Meta envió) —
 * NUNCA sobre `json_encode($payload)` ya decodificado/reserializado, que
 * podría no coincidir byte a byte con el original aunque el contenido
 * "signifique" lo mismo (orden de claves, escapes, espacios).
 *
 * Dos modos, vía `config('services.meta.webhook_signature_mode')`:
 * - 'log-only' (default): registra una advertencia si la firma es inválida
 *   o está ausente, pero deja pasar la petición — cero cambio de
 *   comportamiento funcional mientras no se confirme que Meta realmente
 *   está firmando las entregas a este endpoint.
 * - 'strict': una firma inválida o ausente responde 401 inmediatamente,
 *   sin que la petición llegue nunca al controller — ninguna escritura en
 *   base de datos, ningún job encolado, ninguna llamada de IA.
 *
 * Nunca se loguea el secreto ni la firma completa recibida — solo señales
 * diagnósticas mínimas (modo, si había header, si hay secreto configurado).
 */
class VerifyMetaWebhookSignature
{
    private const SIGNATURE_PREFIX = 'sha256=';

    public function handle(Request $request, Closure $next)
    {
        $mode = config('services.meta.webhook_signature_mode', 'log-only');
        $secret = config('services.meta.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! $this->isValidSignature($request, $secret, $header)) {
            Log::warning('META_WEBHOOK_SIGNATURE_INVALID', [
                'mode' => $mode,
                'has_header' => $header !== '',
                'has_secret_configured' => $secret !== null && $secret !== '',
            ]);

            if ($mode === 'strict') {
                return response('Invalid signature', 401);
            }
        }

        return $next($request);
    }

    private function isValidSignature(Request $request, mixed $secret, string $header): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }

        if (! str_starts_with($header, self::SIGNATURE_PREFIX)) {
            return false;
        }

        $providedHash = substr($header, strlen(self::SIGNATURE_PREFIX));
        $expectedHash = hash_hmac('sha256', $request->getContent(), (string) $secret);

        return hash_equals($expectedHash, $providedHash);
    }
}
