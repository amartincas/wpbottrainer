<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\Reminder;
use App\Models\ReminderSuggestion;
use App\Training\Enums\ReminderSuggestionOrigin;
use App\Training\Enums\ReminderSuggestionStatus;

/**
 * Hito 10 — autoridad DETERMINISTA sobre si el sistema puede ofrecer
 * espontáneamente un recordatorio ahora mismo. La IA nunca decide esto por
 * su cuenta — cada trigger de proactividad (mencionó que se le olvida
 * entrenar / terminó una sesión / preguntó cuándo entrenar) consulta esta
 * puerta ANTES de producir una oferta.
 *
 * Las ventanas de tiempo son valores TÉCNICOS provisionales, marcados
 * explícitamente como decisión de negocio/producto pendiente — no se
 * inventan como si fueran definitivas.
 */
class ReminderProactivityGate
{
    /**
     * // DECISIÓN DE NEGOCIO PENDIENTE — valor técnico provisional. ¿Cada
     * cuánto puede el sistema volver a ofrecer un recordatorio proactivo al
     * mismo contacto, sin importar el resultado de la oferta anterior?
     */
    private const PROACTIVE_COOLDOWN_HOURS = 72;

    /**
     * // DECISIÓN DE NEGOCIO PENDIENTE — valor técnico provisional. Ventana
     * adicional (más larga que la anterior) tras un "no" explícito, antes
     * de volver a ofrecer.
     */
    private const DECLINED_COOLDOWN_HOURS = 168;

    public function canOffer(Contact $contact): bool
    {
        if (ReminderSuggestion::activePendingFor($contact) !== null) {
            return false;
        }

        if (Reminder::activeFor($contact) !== null) {
            return false;
        }

        $lastProactive = ReminderSuggestion::where('contact_id', $contact->id)
            ->where('origin', ReminderSuggestionOrigin::Proactive)
            ->latest('id')
            ->first();

        if ($lastProactive !== null && $lastProactive->created_at->diffInHours(now()) < self::PROACTIVE_COOLDOWN_HOURS) {
            return false;
        }

        $lastDeclined = ReminderSuggestion::where('contact_id', $contact->id)
            ->where('status', ReminderSuggestionStatus::Declined)
            ->latest('id')
            ->first();

        if ($lastDeclined !== null && $lastDeclined->updated_at->diffInHours(now()) < self::DECLINED_COOLDOWN_HOURS) {
            return false;
        }

        return true;
    }
}
