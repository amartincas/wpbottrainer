<?php

namespace App\Models;

use App\Training\Enums\TrainingAccessStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entitlement/acceso vigente al servicio de Training — deliberadamente
 * separado de TrainingProfile. NO es Subscription/Payment/Invoice/
 * Enrollment. Único consumidor real: App\Training\Support\TrainingAccessGate.
 * Ver docs/DECISIONS.md.
 *
 * Hito 12 — además de `PaymentConfirmationService` (la única puerta desde
 * un Payment), `App\Training\Support\TrainingAccessAdministrationService`
 * es la única puerta para transiciones puramente administrativas
 * (Trial/Free/extend/revoke/reactivate) — nunca un formulario genérico de
 * edición en Filament. Cada transición administrativa queda auditada en
 * `TrainingAccessAudit` (append-only, separado de este modelo).
 */
#[Fillable([
    'contact_id',
    'payment_id',
    'status',
    'granted_at',
    'expires_at',
    'granted_by',
    'notes',
    'trial_granted_at',
])]
class TrainingAccess extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TrainingAccessStatus::class,
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            // Hito 15 — marca histórica INMUTABLE (manual O automático, una
            // sola fuente de verdad): se fija una única vez, nunca se
            // sobreescribe. Ver TrainingAccessAdministrationService::
            // markTrialGrantedIfFirstTime() y App\Training\Support\
            // AutomaticTrialProvisioner.
            'trial_granted_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Hito 8: trazabilidad de qué Payment originó/extendió este acceso.
     * Nullable — accesos otorgados manualmente (ej. Hito 7, antes de que
     * Payments existiera, o Trial/Free desde Hito 12) no tienen uno.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Hito 12 — historial append-only de transiciones administrativas
     * sobre ESTA fila. Nunca autoritativo para el acceso técnico actual
     * (eso sigue siendo exclusivamente `status`/`expires_at` de este mismo
     * modelo) — es solo lectura/auditoría.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(TrainingAccessAudit::class);
    }

    /**
     * Hito 12 — `Free` se agrega a los estados que otorgan acceso técnico
     * (junto a Trial/Active, ya existentes) — único cambio de este método
     * en este hito. `TrainingAccessGate` no cambia: sigue llamando
     * exactamente a este mismo método, sin saber que `Free` existe.
     */
    public function isCurrentlyValid(): bool
    {
        if (! in_array($this->status, [TrainingAccessStatus::Trial, TrainingAccessStatus::Active, TrainingAccessStatus::Free], true)) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Hito 12 — fecha base para una extensión: si el acceso sigue vigente,
     * se extiende desde su propio vencimiento (nunca se pierden días ya
     * concedidos); si ya venció (o nunca tuvo fecha), se extiende desde
     * ahora. Réplica EXACTA del criterio que
     * `PaymentConfirmationService::grantAccess()` ya usa (Hito 8/11) —
     * deliberadamente NO se modifica ese método para reutilizar este, para
     * no tocar Payments sin necesidad técnica real (ver docs/DECISIONS.md);
     * este método es el único punto de reutilización para código NUEVO
     * (`TrainingAccessAdministrationService`).
     */
    public function extensionBaseDate(): CarbonInterface
    {
        return $this->expires_at?->isFuture() ? $this->expires_at : now();
    }

    /**
     * Hito 12 — estado EFECTIVO para mostrar al administrador (Filament),
     * NUNCA lo que gobierna el acceso real (eso sigue siendo
     * `isCurrentlyValid()`, sin cambios) ni lo que se persiste (`status`
     * crudo nunca se muta a "expired" — ver docs/DECISIONS.md, sección
     * Expired). Un Trial/Active/Free con `expires_at` ya pasado se
     * muestra como `Expired` aquí, aunque la columna siga diciendo
     * literalmente `trial`/`active`/`free`. Centraliza este cálculo en un
     * solo lugar (reutilizado por la tabla y el detalle de Cliente) en vez
     * de duplicar el mismo ternario en dos clases de Filament.
     */
    public function effectiveStatus(): TrainingAccessStatus
    {
        $isTimeBound = in_array($this->status, [TrainingAccessStatus::Trial, TrainingAccessStatus::Active, TrainingAccessStatus::Free], true);

        if ($isTimeBound && $this->expires_at !== null && $this->expires_at->isPast()) {
            return TrainingAccessStatus::Expired;
        }

        return $this->status;
    }
}
