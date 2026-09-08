<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Models\User;
use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Hito 12 — la ÚNICA puerta para transiciones ADMINISTRATIVAS de
 * `TrainingAccess` (Trial/Free/extend/revoke/reactivate) — deliberadamente
 * separada de `App\Payments\Support\PaymentConfirmationService`, la única
 * puerta para transiciones originadas por un Payment confirmado. Ninguna
 * de las dos depende de la otra (ver `TrainingAccessAdministrationArchTest`).
 *
 * Vive en `App\Training\Support`, no en `App\Payments`, precisamente
 * porque estas acciones son un flujo administrativo distinto del flujo de
 * pagos — nunca crean, editan, ni confirman/rechazan un `Payment`.
 *
 * `Active` NUNCA es un `targetStatus` posible aquí (ni en `reactivate()`
 * ni en ningún otro método) — la única vía a `Active` sigue siendo
 * `PaymentConfirmationService::grantAccess()`, tras un Payment confirmado
 * real. Ver docs/DECISIONS.md.
 *
 * Cada método escribe `TrainingAccess` y crea EXACTAMENTE una fila en
 * `TrainingAccessAudit` (append-only, nunca se edita) — salvo los no-ops
 * idempotentes (`revoke()` sobre un acceso ya revocado, `reactivate()`
 * sobre un acceso que no está revocado), que no auditan nada nuevo porque
 * no hay ninguna transición real que registrar.
 */
class TrainingAccessAdministrationService
{
    /**
     * Otorga un Trial de duración explícita, definida por el
     * administrador en el momento de la acción — nunca una duración
     * global (`Tenant.trial_days` NO existe, deliberadamente, ver
     * docs/DECISIONS.md). `expires_at` es siempre obligatorio para Trial.
     * Disponible sin importar el estado previo del contacto — el
     * administrador decide conscientemente, no hay guarda de "ya tiene
     * acceso".
     */
    public function grantTrial(Contact $contact, User $admin, int $durationDays, ?string $reason = null): TrainingAccess
    {
        $access = TrainingAccess::firstOrNew(['contact_id' => $contact->id]);
        $previousStatus = $access->exists ? $access->status : null;
        $previousExpiresAt = $access->expires_at;

        $access->fill([
            'status' => TrainingAccessStatus::Trial,
            'granted_at' => now(),
            'expires_at' => now()->addDays($durationDays),
            'granted_by' => 'admin_trial',
            'notes' => $reason,
        ])->save();

        $this->recordAudit($access, $admin, TrainingAccessAuditAction::TrialGranted, $previousStatus, $access->status, $previousExpiresAt, $access->expires_at, $reason);

        return $access;
    }

    /**
     * Otorga acceso gratuito administrativo — NUNCA genera un Payment.
     * `$until` nullable = indefinido; una fecha = Free temporal. Ambos
     * casos ya soportados por el esquema existente sin cambios.
     */
    public function grantFree(Contact $contact, User $admin, ?CarbonInterface $until = null, ?string $reason = null): TrainingAccess
    {
        $access = TrainingAccess::firstOrNew(['contact_id' => $contact->id]);
        $previousStatus = $access->exists ? $access->status : null;
        $previousExpiresAt = $access->expires_at;

        $access->fill([
            'status' => TrainingAccessStatus::Free,
            'granted_at' => now(),
            'expires_at' => $until,
            'granted_by' => 'admin_free',
            'notes' => $reason,
        ])->save();

        $this->recordAudit($access, $admin, TrainingAccessAuditAction::FreeGranted, $previousStatus, $access->status, $previousExpiresAt, $access->expires_at, $reason);

        return $access;
    }

    /**
     * Extiende Trial/Free/Active por `$months` — NUNCA cambia `status`,
     * NUNCA crea un Payment, no aplicable a Revoked (debe reactivarse
     * primero). Reutiliza `TrainingAccess::extensionBaseDate()` — el mismo
     * criterio que `PaymentConfirmationService::grantAccess()` ya usa
     * (vigente -> desde `expires_at`; vencido -> desde `now()`), sin
     * duplicar esa lógica ni modificar Payments.
     */
    public function extend(Contact $contact, User $admin, int $months, ?string $reason = null): TrainingAccess
    {
        $access = $contact->trainingAccess;

        if ($access === null) {
            throw new TrainingAccessAdministrationException(
                "No existe TrainingAccess para el contacto #{$contact->id} — no se puede extender."
            );
        }

        if ($access->status === TrainingAccessStatus::Revoked) {
            throw new TrainingAccessAdministrationException(
                'No se puede extender un acceso revocado — reactívelo primero.'
            );
        }

        $previousStatus = $access->status;
        $previousExpiresAt = $access->expires_at;
        $newExpiresAt = $access->extensionBaseDate()->addMonths($months);

        $access->update(['expires_at' => $newExpiresAt]);

        $this->recordAudit($access, $admin, TrainingAccessAuditAction::Extended, $previousStatus, $access->status, $previousExpiresAt, $newExpiresAt, $reason);

        return $access;
    }

    /**
     * Revoca el acceso — motivo SIEMPRE requerido (parámetro no nullable,
     * mismo patrón que `PaymentConfirmationService::reject()`). `expires_at`
     * se CONSERVA sin modificar — nunca se borra. Nunca toca `Payment`.
     * Idempotente: un segundo `revoke()` sobre un acceso ya revocado es un
     * no-op completo, sin fila de auditoría nueva (no hay transición real
     * que registrar) — mismo criterio de idempotencia que Payments.
     */
    public function revoke(Contact $contact, User $admin, string $reason): TrainingAccess
    {
        $access = $contact->trainingAccess;

        if ($access === null) {
            throw new TrainingAccessAdministrationException(
                "No existe TrainingAccess para el contacto #{$contact->id} — no se puede revocar."
            );
        }

        if ($access->status === TrainingAccessStatus::Revoked) {
            Log::info('TRAINING_ACCESS_REVOKE_IDEMPOTENT_NOOP', ['contact_id' => $contact->id]);

            return $access;
        }

        $previousStatus = $access->status;
        $previousExpiresAt = $access->expires_at;

        $access->update(['status' => TrainingAccessStatus::Revoked]);

        $this->recordAudit($access, $admin, TrainingAccessAuditAction::Revoked, $previousStatus, $access->status, $previousExpiresAt, $access->expires_at, $reason);

        return $access;
    }

    /**
     * Reactiva un acceso revocado — SOLO hacia `Trial` o `Free`, NUNCA
     * `Active` (la única vía a Active es un Payment confirmado real, ver
     * docs/DECISIONS.md). La fecha es siempre explícita, decidida por el
     * administrador en el momento — NUNCA se restaura automáticamente el
     * `expires_at` que tenía antes de revocar (podría estar en el pasado,
     * produciendo una reactivación inválida de inmediato). Obligatoria
     * para Trial (no tiene sentido un Trial sin vencimiento); opcional
     * para Free (indefinido si se omite).
     *
     * Si el acceso actual NO está revocado, es un no-op seguro (no rompe
     * el flujo, no audita nada — no hay ninguna transición real que
     * registrar; en la práctica Filament ni siquiera muestra esta acción
     * fuera de un acceso Revoked).
     */
    public function reactivate(Contact $contact, User $admin, TrainingAccessStatus $targetStatus, ?CarbonInterface $until, ?string $reason = null): TrainingAccess
    {
        if (! in_array($targetStatus, [TrainingAccessStatus::Trial, TrainingAccessStatus::Free], true)) {
            throw new TrainingAccessAdministrationException(
                'reactivate() solo admite Trial o Free como estado destino — Active únicamente proviene de un Payment confirmado.'
            );
        }

        if ($targetStatus === TrainingAccessStatus::Trial && $until === null) {
            throw new TrainingAccessAdministrationException(
                'Reactivar a Trial requiere una fecha de vencimiento explícita.'
            );
        }

        $access = $contact->trainingAccess;

        if ($access === null || $access->status !== TrainingAccessStatus::Revoked) {
            Log::info('TRAINING_ACCESS_REACTIVATE_NOOP_NOT_REVOKED', ['contact_id' => $contact->id]);

            return $access ?? TrainingAccess::firstOrNew(['contact_id' => $contact->id]);
        }

        $previousStatus = $access->status;
        $previousExpiresAt = $access->expires_at;

        $access->update([
            'status' => $targetStatus,
            'granted_at' => now(),
            'expires_at' => $until,
        ]);

        $this->recordAudit($access, $admin, TrainingAccessAuditAction::Reactivated, $previousStatus, $access->status, $previousExpiresAt, $access->expires_at, $reason);

        return $access;
    }

    private function recordAudit(
        TrainingAccess $access,
        User $admin,
        TrainingAccessAuditAction $action,
        ?TrainingAccessStatus $previousStatus,
        TrainingAccessStatus $newStatus,
        ?CarbonInterface $previousExpiresAt,
        ?CarbonInterface $newExpiresAt,
        ?string $reason,
    ): void {
        TrainingAccessAudit::create([
            'contact_id' => $access->contact_id,
            'training_access_id' => $access->id,
            'action' => $action,
            'performed_by' => $admin->id,
            'previous_status' => $previousStatus?->value,
            'new_status' => $newStatus->value,
            'previous_expires_at' => $previousExpiresAt,
            'new_expires_at' => $newExpiresAt,
            'reason' => $reason,
        ]);
    }
}
