<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hito 11 — migración de DATOS (no de esquema): compatibilidad para
 * Tenants existentes que ya operaban bajo el modelo anterior de precio
 * único (`Tenant.monthly_price`). Crea, para cada Tenant con
 * `monthly_price` no nulo, un `MembershipPlan` de 1 mes equivalente — para
 * que sigan pudiendo vender membresías bajo el nuevo modelo sin que el
 * administrador tenga que configurar nada manualmente.
 *
 * Deliberadamente NO toca:
 * - `payments` existentes (ningún Payment histórico cambia de valor);
 * - `training_accesses` existentes;
 * - ningún precio ni duración ya concedida.
 *
 * Solo agrega la opción de catálogo necesaria — `Tenant.monthly_price` se
 * mantiene intacto (no se borra en este hito), pero deja de ser la fuente
 * de verdad para Payments NUEVOS una vez que existe este `MembershipPlan`
 * (ver `App\Payments\Handlers\PaymentHandler::handlePaymentInstructions()`).
 *
 * `label` distintivo ("1 mes (migrado)") para que `down()` pueda revertir
 * con precisión — nunca borra un `MembershipPlan` creado manualmente por
 * un administrador después de este hito, aunque también sea de 1 mes.
 */
return new class extends Migration
{
    private const LEGACY_LABEL = '1 mes (migrado)';

    public function up(): void
    {
        $tenants = DB::table('tenants')
            ->whereNotNull('monthly_price')
            ->get(['id', 'monthly_price', 'currency']);

        $now = now();

        foreach ($tenants as $tenant) {
            DB::table('membership_plans')->insert([
                'tenant_id' => $tenant->id,
                'label' => self::LEGACY_LABEL,
                'duration_months' => 1,
                'price' => $tenant->monthly_price,
                'currency' => $tenant->currency,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('membership_plans')->where('label', self::LEGACY_LABEL)->delete();
    }
};
