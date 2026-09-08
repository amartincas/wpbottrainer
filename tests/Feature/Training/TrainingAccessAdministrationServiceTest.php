<?php

use App\Models\Contact;
use App\Models\Payment;
use App\Models\TrainingAccess;
use App\Models\TrainingAccessAudit;
use App\Models\User;
use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationException;
use App\Training\Support\TrainingAccessAdministrationService;

/**
 * Hito 12 — la única puerta para transiciones ADMINISTRATIVAS de
 * TrainingAccess. Nunca crea/edita un Payment. TrainingAccessGate no se
 * toca — solo se prueba aquí el efecto sobre isCurrentlyValid()/status.
 */

function taAdmin(): TrainingAccessAdministrationService
{
    return app(TrainingAccessAdministrationService::class);
}

// ── grantTrial ───────────────────────────────────────────────────────────

it('grants a Trial with the exact duration given by the administrator, never a global default', function () {
    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true]);

    $access = taAdmin()->grantTrial($contact, $admin, 7, 'primer contacto');

    expect($access->status)->toBe(TrainingAccessStatus::Trial);
    expect($access->expires_at->diffInDays(now(), true))->toBeGreaterThan(6)->toBeLessThan(8);
    expect($access->isCurrentlyValid())->toBeTrue();
    expect($access->notes)->toBe('primer contacto');
});

it('grantTrial works even when the contact already has some access — the admin decides consciously', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $access = taAdmin()->grantTrial($contact, $admin, 5);

    expect($access->fresh()->status)->toBe(TrainingAccessStatus::Trial);
});

it('grantTrial records exactly one TrainingAccessAudit row with the correct snapshot', function () {
    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true]);

    $access = taAdmin()->grantTrial($contact, $admin, 5, 'cortesía');

    $audit = TrainingAccessAudit::where('contact_id', $contact->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe(TrainingAccessAuditAction::TrialGranted);
    expect($audit->performed_by)->toBe($admin->id);
    expect($audit->previous_status)->toBeNull(); // no existía TrainingAccess antes
    expect($audit->new_status)->toBe(TrainingAccessStatus::Trial);
    expect($audit->reason)->toBe('cortesía');
    expect(TrainingAccessAudit::where('contact_id', $contact->id)->count())->toBe(1);
});

it('grantTrial never creates a Payment', function () {
    $contact = Contact::factory()->create();
    $before = Payment::count();

    taAdmin()->grantTrial($contact, User::factory()->create(['is_super_admin' => true]), 5);

    expect(Payment::count())->toBe($before);
});

// ── grantFree ────────────────────────────────────────────────────────────

it('grants Free with expires_at = null as indefinite access', function () {
    $contact = Contact::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true]);

    $access = taAdmin()->grantFree($contact, $admin);

    expect($access->status)->toBe(TrainingAccessStatus::Free);
    expect($access->expires_at)->toBeNull();
    expect($access->isCurrentlyValid())->toBeTrue(); // Free se agrega a los estados válidos
});

it('grants Free with an explicit expiration as temporary access', function () {
    $contact = Contact::factory()->create();
    $until = now()->addDays(30);

    $access = taAdmin()->grantFree($contact, User::factory()->create(['is_super_admin' => true]), $until);

    expect($access->status)->toBe(TrainingAccessStatus::Free);
    expect($access->expires_at->toDateTimeString())->toBe($until->toDateTimeString());
});

it('grantFree never creates a Payment', function () {
    $contact = Contact::factory()->create();
    $before = Payment::count();

    taAdmin()->grantFree($contact, User::factory()->create(['is_super_admin' => true]));

    expect(Payment::count())->toBe($before);
});

// ── extend ───────────────────────────────────────────────────────────────

it('extend() on a still-valid access extends from its CURRENT expires_at, never from now', function (TrainingAccessStatus $status) {
    $contact = Contact::factory()->create();
    $currentExpiry = now()->addDays(10);
    $access = TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => $status, 'expires_at' => $currentExpiry]);

    taAdmin()->extend($contact, User::factory()->create(['is_super_admin' => true]), 1, 'gesto comercial');

    $fresh = $access->fresh();
    expect($fresh->status)->toBe($status); // extend() NUNCA cambia status
    expect($fresh->expires_at->diffInDays($currentExpiry, true))->toBeGreaterThan(27); // ~1 mes desde el vencimiento vigente
})->with([TrainingAccessStatus::Trial, TrainingAccessStatus::Free, TrainingAccessStatus::Active]);

it('extend() on an already-expired access extends from NOW, not from the past expiry', function () {
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active, 'expires_at' => now()->subDays(20)]);

    taAdmin()->extend($contact, User::factory()->create(['is_super_admin' => true]), 1);

    $fresh = $access->fresh();
    expect($fresh->expires_at->diffInDays(now(), true))->toBeGreaterThan(27); // ~1 mes desde AHORA, no desde el pasado
});

it('extend() throws on a Revoked access — must be reactivated first', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);

    taAdmin()->extend($contact, User::factory()->create(['is_super_admin' => true]), 1);
})->throws(TrainingAccessAdministrationException::class);

it('extend() throws when the contact has no TrainingAccess at all', function () {
    $contact = Contact::factory()->create();

    taAdmin()->extend($contact, User::factory()->create(['is_super_admin' => true]), 1);
})->throws(TrainingAccessAdministrationException::class);

it('extend() never creates a Payment', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Free]);
    $before = Payment::count();

    taAdmin()->extend($contact, User::factory()->create(['is_super_admin' => true]), 1);

    expect(Payment::count())->toBe($before);
});

it('extend() records exactly one audit row with previous/new expires_at', function () {
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Free, 'expires_at' => now()->addDays(5)]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    taAdmin()->extend($contact, $admin, 1, 'compensación');

    $audit = TrainingAccessAudit::where('contact_id', $contact->id)->first();
    expect($audit->action)->toBe(TrainingAccessAuditAction::Extended);
    expect($audit->previous_status)->toBe(TrainingAccessStatus::Free);
    expect($audit->new_status)->toBe(TrainingAccessStatus::Free); // sin cambio de status
    expect($audit->previous_expires_at->equalTo($access->expires_at))->toBeTrue();
    expect($audit->reason)->toBe('compensación');
});

// ── revoke ───────────────────────────────────────────────────────────────

it('revoke() requires a reason and preserves expires_at without modifying it', function () {
    $contact = Contact::factory()->create();
    $expiry = now()->addDays(15);
    $access = TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active, 'expires_at' => $expiry]);

    taAdmin()->revoke($contact, User::factory()->create(['is_super_admin' => true]), 'incumplimiento de términos');

    $fresh = $access->fresh();
    expect($fresh->status)->toBe(TrainingAccessStatus::Revoked);
    expect($fresh->expires_at->toDateTimeString())->toBe($expiry->toDateTimeString()); // NUNCA se borra
    expect($fresh->isCurrentlyValid())->toBeFalse();
});

it('revoking an already-revoked access is a no-op: no second audit row, reason unchanged', function () {
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    taAdmin()->revoke($contact, $admin, 'primer motivo');
    taAdmin()->revoke($contact, $admin, 'segundo motivo, ignorado');

    expect(TrainingAccessAudit::where('contact_id', $contact->id)->count())->toBe(0); // ya estaba revoked antes de la primera llamada real
});

it('revoke() never creates a Payment', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active]);
    $before = Payment::count();

    taAdmin()->revoke($contact, User::factory()->create(['is_super_admin' => true]), 'motivo');

    expect(Payment::count())->toBe($before);
});

it('revoke() throws when the contact has no TrainingAccess at all', function () {
    $contact = Contact::factory()->create();

    taAdmin()->revoke($contact, User::factory()->create(['is_super_admin' => true]), 'motivo');
})->throws(TrainingAccessAdministrationException::class);

it('TrainingAccessGate denies access after revoke — verified via the real Gate, not re-implemented logic', function () {
    $contact = Contact::factory()->create();
    \App\Models\TrainingProfile::factory()->create(['contact_id' => $contact->id]);
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active]);

    taAdmin()->revoke($contact, User::factory()->create(['is_super_admin' => true]), 'motivo');

    $result = app(\App\Training\Support\TrainingAccessGate::class)->authorize($contact->fresh());
    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('access_invalid');
});

// ── reactivate ───────────────────────────────────────────────────────────

it('reactivate() to Trial requires an explicit expires_at — throws without one', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);

    taAdmin()->reactivate($contact, User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Trial, null);
})->throws(TrainingAccessAdministrationException::class);

it('reactivate() to Free allows a null expires_at (indefinite)', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);

    $access = taAdmin()->reactivate($contact, User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Free, null);

    expect($access->fresh()->status)->toBe(TrainingAccessStatus::Free);
    expect($access->fresh()->expires_at)->toBeNull();
});

it('reactivate() to Active is forbidden — Active can only originate from a confirmed Payment', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);

    taAdmin()->reactivate($contact, User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Active, now()->addMonth());
})->throws(TrainingAccessAdministrationException::class);

it('reactivate() NEVER auto-restores the pre-revoke expires_at — the date is always what the admin explicitly gives now', function () {
    $contact = Contact::factory()->create();
    $preRevokeExpiry = now()->addDays(20); // fecha que tenía ANTES de revocar
    $access = TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active, 'expires_at' => $preRevokeExpiry]);
    taAdmin()->revoke($contact, User::factory()->create(['is_super_admin' => true]), 'motivo');

    $newExplicitDate = now()->addDays(3); // el admin da una fecha DISTINTA, corta, a propósito
    taAdmin()->reactivate($contact->fresh(), User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Trial, $newExplicitDate);

    $fresh = $access->fresh();
    expect($fresh->expires_at->toDateTimeString())->not->toBe($preRevokeExpiry->toDateTimeString()); // NUNCA restaurada
    expect($fresh->expires_at->toDateTimeString())->toBe($newExplicitDate->toDateTimeString()); // exactamente la que el admin dio
});

it('reactivate() on an access that is NOT revoked is a safe no-op — no audit row, no exception', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->create(['contact_id' => $contact->id, 'status' => TrainingAccessStatus::Active]);

    $result = taAdmin()->reactivate($contact, User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Trial, now()->addDays(5));

    expect($result->status)->toBe(TrainingAccessStatus::Active); // sin cambio
    expect(TrainingAccessAudit::where('contact_id', $contact->id)->count())->toBe(0);
});

it('reactivate() never creates a Payment', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);
    $before = Payment::count();

    taAdmin()->reactivate($contact, User::factory()->create(['is_super_admin' => true]), TrainingAccessStatus::Free, null);

    expect(Payment::count())->toBe($before);
});

it('reactivate() records exactly one audit row on a real transition', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->revoked()->create(['contact_id' => $contact->id]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    taAdmin()->reactivate($contact, $admin, TrainingAccessStatus::Free, null, 'buena fe');

    $audit = TrainingAccessAudit::where('contact_id', $contact->id)->first();
    expect($audit->action)->toBe(TrainingAccessAuditAction::Reactivated);
    expect($audit->previous_status)->toBe(TrainingAccessStatus::Revoked);
    expect($audit->new_status)->toBe(TrainingAccessStatus::Free);
    expect($audit->reason)->toBe('buena fe');
});

// ── isCurrentlyValid() con Free ─────────────────────────────────────────

it('isCurrentlyValid() treats Free (indefinite) as valid access', function () {
    $access = TrainingAccess::factory()->create(['status' => TrainingAccessStatus::Free, 'expires_at' => null]);

    expect($access->isCurrentlyValid())->toBeTrue();
});

it('isCurrentlyValid() treats an EXPIRED Free (past date) as invalid, without mutating status', function () {
    $access = TrainingAccess::factory()->create(['status' => TrainingAccessStatus::Free, 'expires_at' => now()->subDay()]);

    expect($access->isCurrentlyValid())->toBeFalse();
    expect($access->status)->toBe(TrainingAccessStatus::Free); // status crudo NUNCA se muta a "expired"
});

// ── TrainingAccessAudit — integridad append-only ────────────────────────

it('TrainingAccessAudit rows are never updated after creation — no updated_at column', function () {
    $audit = \App\Models\TrainingAccessAudit::factory()->create();

    expect($audit->getAttributes())->not->toHaveKey('updated_at');
});

// ── extendByDays() (Hito 13, usado por Referrals) ───────────────────────

it('extendByDays() on a still-valid access extends from its CURRENT expires_at, never from now', function () {
    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Active,
        'expires_at' => now()->addDays(10),
    ]);
    $rewardId = \App\Referrals\Models\ReferralReward::factory()->create()->id;

    $result = taAdmin()->extendByDays($contact, null, 3, 'recompensa de prueba', $rewardId);

    expect($result->expires_at->toDateString())->toBe(now()->addDays(13)->toDateString());
    expect($result->status)->toBe(TrainingAccessStatus::Active); // nunca cambia el status
});

it('extendByDays() on an already-expired access extends from NOW, not from the past expiry', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Trial,
        'expires_at' => now()->subDays(5),
    ]);
    $rewardId = \App\Referrals\Models\ReferralReward::factory()->create()->id;

    $result = taAdmin()->extendByDays($contact, null, 3, null, $rewardId);

    expect($result->expires_at->toDateString())->toBe(now()->addDays(3)->toDateString());
});

it('extendByDays() on Free INDEFINIDO (expires_at null) does NOT modify anything and does NOT write a TrainingAccessAudit row', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create([
        'status' => TrainingAccessStatus::Free,
        'expires_at' => null,
    ]);

    $countBefore = TrainingAccessAudit::count();
    $result = taAdmin()->extendByDays($contact, null, 3, null, 999);

    expect($result->expires_at)->toBeNull();
    expect($result->status)->toBe(TrainingAccessStatus::Free);
    expect(TrainingAccessAudit::count())->toBe($countBefore); // ni una fila — no hubo transición real
});

it('extendByDays() throws on a Revoked access — never auto-reactivates', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->revoked()->create();

    taAdmin()->extendByDays($contact, null, 3, null, 999);
})->throws(TrainingAccessAdministrationException::class);

it('extendByDays() throws when the contact has no TrainingAccess at all', function () {
    $contact = Contact::factory()->create();

    taAdmin()->extendByDays($contact, null, 3, null, 999);
})->throws(TrainingAccessAdministrationException::class);

it('extendByDays() never creates a Payment', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active]);
    $paymentCountBefore = Payment::count(); // el propio setup del reward de prueba crea un Payment ajeno
    $rewardId = \App\Referrals\Models\ReferralReward::factory()->create()->id;
    $paymentCountAfterRewardSetup = Payment::count();

    taAdmin()->extendByDays($contact, null, 3, null, $rewardId);

    // extendByDays() en sí mismo no crea ningún Payment adicional al ya
    // creado por el setup del reward de prueba.
    expect(Payment::count())->toBe($paymentCountAfterRewardSetup);
});

it('extendByDays() records exactly one audit row, with performed_by NULL and referral_reward_id set', function () {
    $contact = Contact::factory()->create();
    TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active, 'expires_at' => now()->addDays(5)]);
    $rewardId = \App\Referrals\Models\ReferralReward::factory()->create()->id;

    $result = taAdmin()->extendByDays($contact, null, 3, 'motivo de prueba', $rewardId);

    $audit = TrainingAccessAudit::where('training_access_id', $result->id)->sole();
    expect($audit->action)->toBe(TrainingAccessAuditAction::Extended);
    expect($audit->performed_by)->toBeNull();
    expect($audit->referral_reward_id)->toBe($rewardId);
    expect($audit->reason)->toBe('motivo de prueba');
});

// ── recordAudit() — invariante estructural performed_by XOR referral_reward_id ──

it('recordAudit() throws if BOTH admin and referralRewardId would be provided — enforced structurally, not just by tests', function () {
    // grantTrial() siempre pasa un $admin real y nunca un referralRewardId
    // — no hay forma pública de invocar la violación directamente sin
    // reflection, así que se prueba a través del único método que SÍ
    // permite variar ambos parámetros: extendByDays(). Un $admin no-null Y
    // un $referralRewardId no-null a la vez debe ser rechazado.
    $method = new ReflectionMethod(TrainingAccessAdministrationService::class, 'recordAudit');
    $method->setAccessible(true);

    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $method->invoke(
        taAdmin(),
        $access,
        $admin, // admin no-null
        TrainingAccessAuditAction::Extended,
        $access->status,
        $access->status,
        $access->expires_at,
        $access->expires_at,
        null,
        123, // Y referralRewardId no-null — inválido
    );
})->throws(TrainingAccessAdministrationException::class);

it('recordAudit() throws if NEITHER admin nor referralRewardId is provided', function () {
    $method = new ReflectionMethod(TrainingAccessAdministrationService::class, 'recordAudit');
    $method->setAccessible(true);

    $contact = Contact::factory()->create();
    $access = TrainingAccess::factory()->for($contact)->create(['status' => TrainingAccessStatus::Active]);

    $method->invoke(
        taAdmin(),
        $access,
        null, // ni admin...
        TrainingAccessAuditAction::Extended,
        $access->status,
        $access->status,
        $access->expires_at,
        $access->expires_at,
        null,
        null, // ...ni referralRewardId — inválido
    );
})->throws(TrainingAccessAdministrationException::class);
