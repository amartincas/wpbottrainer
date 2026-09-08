<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Payment;
use App\Payments\Enums\PaymentMethodType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Hito 11 — por defecto, un Payment de test ya trae una membresía
     * elegida (el caso más común que los tests existentes asumen: "un
     * Payment esperando comprobante", no "esperando elegir plan"). Se
     * resuelve el `MembershipPlan` activo del Tenant del `contact_id` ya
     * resuelto (mismo patrón de closures dependientes que
     * ReminderFactory/ReminderSuggestionFactory, Hito 10) — si no hay
     * ninguno (Tenant sin `monthly_price`, o un test que lo borró
     * explícitamente), cae a los valores fijos que esta factory ya usaba
     * antes de este hito, para no romper ningún test que dependa de ellos.
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'membership_plan_id' => function (array $attributes) {
                $contact = Contact::find($attributes['contact_id']);

                return $contact ? MembershipPlan::where('tenant_id', $contact->tenant_id)->where('is_active', true)->value('id') : null;
            },
            'membership_months' => function (array $attributes) {
                $plan = MembershipPlan::find($attributes['membership_plan_id']);

                return $plan?->duration_months ?? 1;
            },
            'amount' => function (array $attributes) {
                $plan = MembershipPlan::find($attributes['membership_plan_id']);

                return $plan?->price ?? 50000;
            },
            'currency' => function (array $attributes) {
                $plan = MembershipPlan::find($attributes['membership_plan_id']);

                return $plan?->currency ?? 'COP';
            },
            'method' => PaymentMethodType::ManualTransfer,
            'method_label' => 'Nequi',
            'reference' => null,
            'status' => PaymentStatus::Pending,
            'extracted_data' => null,
            'validation_flags' => null,
            'receipt_submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'expires_at' => now()->addHours(48),
        ];
    }

    /**
     * Hito 11 — el otro estado real de un Payment recién creado: método ya
     * elegido, membresía todavía SIN elegir. `needsPlanSelection()` debe
     * devolver `true` para el resultado de este estado.
     */
    public function awaitingPlanSelection(): static
    {
        return $this->state(fn () => [
            'membership_plan_id' => null,
            'membership_months' => null,
            'amount' => null,
            'currency' => null,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::UnderReview,
            'reference' => (string) fake()->numerify('#########'),
            'receipt_submitted_at' => now(),
            'extracted_data' => [
                'amount' => 50000, 'date' => now()->format('Y-m-d'), 'time' => null,
                'reference' => (string) fake()->numerify('#########'), 'entity' => 'Nequi',
                'payer_name' => null, 'uncertain' => false,
            ],
            'validation_flags' => [],
        ]);
    }

    public function confirmed(): static
    {
        return $this->underReview()->state(fn () => [
            'status' => PaymentStatus::Confirmed,
            'reviewed_at' => now(),
        ]);
    }
}
