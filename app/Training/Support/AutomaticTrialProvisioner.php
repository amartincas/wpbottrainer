<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\Payment;
use App\Models\TrainingAccess;
use Illuminate\Support\Facades\DB;

/**
 * Hito 15 — concesión AUTOMÁTICA de Trial (sin actor humano), disparada
 * ÚNICAMENTE por `App\Training\Handlers\TrainingHandler` cuando
 * `TrainingAccessGate::authorize()` deniega con motivo `'no_access'` (fila
 * de `TrainingAccess` inexistente) — nunca desde ningún otro punto. Un
 * Contact con una fila `TrainingAccess` histórica (vencida o revocada) cae
 * siempre en `'access_invalid'`, no en `'no_access'`, así que este
 * mecanismo nunca se evalúa para él, sin importar el resultado de
 * `isEligible()` — es una decisión de diseño explícita, no una limitación
 * incidental (ver docs/DECISIONS.md).
 *
 * Regla de elegibilidad, determinista, sin IA — UNA sola, con dos
 * condiciones:
 *
 *   nunca recibió Trial (trial_granted_at IS NULL)
 *   AND
 *   nunca tuvo un Payment Confirmed (fuente de verdad histórica de que ya
 *   fue cliente de pago)
 *
 * El canal de adquisición (referral/meta_ads/direct) NUNCA entra en esta
 * regla. Deliberadamente NO importa nada de `App\Payments` — `App\Models\
 * Payment` vive en el namespace neutral de modelos compartidos (igual que
 * `TrainingAccess`/`Contact`), y el estado `Confirmed` se compara contra su
 * valor crudo (`'confirmed'`) en vez de importar `App\Payments\Enums\
 * PaymentStatus`, preservando que `App\Training` siga sin ninguna
 * dependencia real del namespace `App\Payments` (ver
 * TrainingAccessAdministrationArchTest).
 */
class AutomaticTrialProvisioner
{
    public function __construct(
        private readonly TrainingAccessAdministrationService $trainingAccessService,
    ) {}

    /**
     * Transaccional: bloquea (`lockForUpdate()`) la fila del propio Contact
     * — garantizada presente incluso cuando `TrainingAccess` todavía no
     * existe — para serializar dos intentos concurrentes del mismo
     * Contact, y REVALIDA la elegibilidad DENTRO del lock (nunca confía en
     * un chequeo previo a la transacción). Devuelve `null` si no es
     * elegible — nunca lanza por "no elegible", eso es un resultado
     * esperado, no un error.
     */
    public function provisionIfEligible(Contact $contact): ?TrainingAccess
    {
        return DB::transaction(function () use ($contact) {
            $locked = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();

            if (! $this->isEligible($locked)) {
                return null;
            }

            return $this->trainingAccessService->grantAutomaticTrial(
                $locked,
                $locked->tenant->trial_duration_days,
            );
        });
    }

    private function isEligible(Contact $contact): bool
    {
        return $contact->trainingAccess?->trial_granted_at === null
            && ! Payment::where('contact_id', $contact->id)->where('status', 'confirmed')->exists();
    }
}
