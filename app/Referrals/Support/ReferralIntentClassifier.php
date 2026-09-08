<?php

namespace App\Referrals\Support;

use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Intent;
use App\Core\Messaging\IntentClassifierInterface;

/**
 * Clasificación determinista de "¿este mensaje pide algo del programa de
 * Referidos?" (Hito 13) — mismo patrón que TrainingIntentClassifier/
 * PaymentIntentClassifier: solo palabras clave, sin IA, sin señal de
 * estado adicional (a diferencia de Payment, aquí no hay un "Referral
 * abierto" que justifique clasificar sin palabra clave).
 *
 * Deliberadamente NO detecta un código de referido embebido — eso es
 * atribución (App\Referrals\Support\ReferralAttributionPreRoutingScreen),
 * corre ANTES del Router, y nunca reclama el Intent del mensaje.
 */
class ReferralIntentClassifier implements IntentClassifierInterface
{
    private const KEYWORDS = [
        'mi código', 'mi codigo', 'referir', 'invitar amigos', 'invitar a un amigo',
        'mi invitación', 'mi invitacion', 'mis referidos', 'programa de referidos',
        'código de referido', 'codigo de referido', 'quiero referir',
        // Deben coincidir con ReferralHandler::STATS_KEYWORDS — de lo
        // contrario una pregunta de estadísticas nunca llega a clasificar
        // como Intent::Referral y cae en fallback_chat (hallazgo real de
        // la prueba E2E: "cuántos referidos tengo" nunca alcanzaba a
        // ReferralHandler porque solo el Handler, no el Classifier,
        // reconocía la frase).
        'cuántos referidos', 'cuantos referidos', 'cuántos he referido', 'cuantos he referido',
    ];

    public function classify(ExecutionContext $context): ?Intent
    {
        $body = mb_strtolower($context->message->messageBody ?? '');

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($body, $keyword)) {
                return Intent::Referral;
            }
        }

        return null;
    }
}
