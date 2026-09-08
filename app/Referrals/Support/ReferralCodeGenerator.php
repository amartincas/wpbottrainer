<?php

namespace App\Referrals\Support;

use App\Referrals\Models\ReferralCode;
use Illuminate\Support\Str;

/**
 * Hito 13 — genera el código público de un referente. Determinista en su
 * FORMA (alfabeto sin ambigüedades: sin 0/O/1/I), aleatorio en su VALOR.
 * Reintenta en colisión (extremadamente improbable con 32^6 combinaciones)
 * — el índice único de `referral_codes.code` es la garantía real, esto es
 * solo la generación en sí.
 */
class ReferralCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 6;

    private const PREFIX = 'REF-';

    private const MAX_ATTEMPTS = 10;

    public function generateUnique(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::PREFIX.$this->randomSuffix();

            if (! ReferralCode::where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No se pudo generar un código de referido único después de '.self::MAX_ATTEMPTS.' intentos.');
    }

    private function randomSuffix(): string
    {
        return collect(range(1, self::LENGTH))
            ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
            ->implode('');
    }

    /**
     * Regex determinista (sin IA) para detectar un código embebido en un
     * mensaje de WhatsApp entrante. Case-insensitive porque el usuario
     * puede escribir/editar el texto prellenado del link wa.me antes de
     * enviarlo.
     */
    public function extractFromText(string $body): ?string
    {
        $pattern = '/\bREF-(['.preg_quote(self::ALPHABET, '/').']{'.self::LENGTH.'})\b/i';

        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return self::PREFIX.mb_strtoupper($matches[1]);
    }
}
