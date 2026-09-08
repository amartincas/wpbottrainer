<?php

namespace App\Training\Enums;

/**
 * Hito 12 — `Free` (acceso gratuito otorgado administrativamente, nunca vía
 * `Payment`) se agrega como caso válido, sin ningún cambio de esquema — es
 * un enum de string, la columna `training_accesses.status` ya lo admite.
 * `Active` sigue significando exclusivamente "hay un Payment confirmado
 * detrás" — un acceso administrativo sin pago SIEMPRE es `Trial` o `Free`,
 * nunca `Active`. Ver docs/DECISIONS.md.
 */
enum TrainingAccessStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Free = 'free';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
