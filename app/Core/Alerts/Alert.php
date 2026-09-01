<?php

namespace App\Core\Alerts;

/**
 * Infraestructura transversal de Core (Hito 7.1) — NO pertenece a Training
 * ni a Payments ni a ningún otro dominio. Un dominio construye una Alert
 * para describir QUÉ pasó; nunca decide POR DÓNDE se entrega ni A QUIÉN —
 * eso lo resuelve AlertService + los canales registrados. Ver
 * docs/DECISIONS.md.
 *
 * `category` es un string libre a propósito (no un enum cerrado): los
 * emisores son abiertos ("Safety, Payments, WhatsApp/Meta, AI, Queue,
 * Infrastructure, futuros módulos") — un enum en Core obligaría a tocar
 * Core cada vez que un dominio nuevo necesite alertar, justo lo que este
 * mecanismo evita.
 *
 * Inmutable (readonly) — una Alert nunca se modifica después de creada, ni
 * siquiera por un canal.
 */
final class Alert
{
    /**
     * Nombres de clave de `context` que nunca deben llegar a un canal en
     * texto plano — coincidencia por substring, insensible a mayúsculas.
     * Defensa sistémica además de la disciplina en cada punto de emisión:
     * ningún canal puede ver un valor cuya clave luzca como un secreto,
     * sin importar qué dominio construyó la Alert.
     */
    private const SENSITIVE_CONTEXT_KEY_PATTERNS = [
        'key', 'token', 'password', 'secret', 'credential',
    ];

    public readonly array $context;

    public function __construct(
        public readonly string $category,
        public readonly AlertSeverity $severity,
        public readonly string $message,
        array $context = [],
    ) {
        $this->context = self::sanitizeContext($context);
    }

    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $sanitized[$key] = self::looksSensitive((string) $key) ? '[REDACTED]' : $value;
        }

        return $sanitized;
    }

    private static function looksSensitive(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::SENSITIVE_CONTEXT_KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
