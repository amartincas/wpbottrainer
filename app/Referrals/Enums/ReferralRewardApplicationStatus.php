<?php

namespace App\Referrals\Enums;

/**
 * Distingue explícitamente "la recompensa se generó" (siempre, una vez que
 * el referido hace su primera compra confirmada) de "el acceso del
 * referente se modificó materialmente" — nunca se usa `Applied` cuando el
 * acceso realmente no cambió. Ver docs/DECISIONS.md.
 */
enum ReferralRewardApplicationStatus: string
{
    /** TrainingAccess del referente se extendió realmente (expires_at cambió). */
    case Applied = 'applied';

    /**
     * El referente tenía Free indefinido (expires_at ya null) — ya
     * ilimitado, no hay nada que extender. La recompensa es real y se
     * procesó, pero no produce ningún cambio numérico observable.
     */
    case NotApplicable = 'not_applicable';

    /**
     * El referente está Revoked o no tiene TrainingAccess en absoluto —
     * NUNCA se auto-reactiva. La recompensa queda estructuralmente
     * identificada como pendiente (consultable directamente, sin depender
     * de AlertLog) para resolución manual vía las acciones administrativas
     * ya existentes de Hito 12 (Reactivar + Extender).
     */
    case Pending = 'pending';
}
