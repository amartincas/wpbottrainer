<?php

namespace App\ExerciseCatalog\DTOs;

/**
 * Hito 9.1 — resultado de resolver un video EN EL MOMENTO del envío. Nunca
 * se persiste — vive solo en memoria durante el turno de conversación que
 * lo necesita. `expiresAt` es informativo (para logging/diagnóstico), no
 * algo que este DTO haga cumplir por sí mismo.
 */
final readonly class ResolvedMedia
{
    public function __construct(
        public string $url,
        public string $contentType,
        public ?\DateTimeImmutable $expiresAt,
    ) {}
}
