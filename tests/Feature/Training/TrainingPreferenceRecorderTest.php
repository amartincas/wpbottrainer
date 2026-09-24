<?php

use App\Models\Contact;
use App\Models\Exercise;
use App\Models\TrainingPreference;
use App\Training\Enums\PreferenceDimension;
use App\Training\Enums\PreferenceStatus;
use App\Training\Support\TrainingPreferenceRecorder;
use Illuminate\Database\QueryException;

/**
 * Hito B3 (diseño v3 FINAL, Sección A.1/8) — lifecycle exacto: UNA sola fila
 * por `(contact_id, preference_key)` durante toda la vida del contacto.
 * Ejemplo completo del encargo (burpees: create -> duplicate -> revoke ->
 * redeclare meses después) verificado explícitamente.
 */
function preferenceRecorder(): TrainingPreferenceRecorder
{
    return new TrainingPreferenceRecorder;
}

it('creates a new active preference on first declaration', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();

    $preference = preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');

    expect($preference->status)->toBe(PreferenceStatus::Active);
    expect($preference->dimension)->toBe(PreferenceDimension::Exercise);
    expect($preference->exercise_id)->toBe($exercise->id);
    expect($preference->preference_key)->toBe("exercise:{$exercise->id}");
    expect($preference->original_text)->toBe('No me gustan los burpees.');
    expect($preference->created_at)->not->toBeNull();
    expect($preference->revoked_at)->toBeNull();
    expect($preference->reactivated_at)->toBeNull();
});

it('is idempotent: redeclaring an already-active preference never creates a second row', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();

    preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');
    preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
});

it('revokes an active preference', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();

    preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');
    preferenceRecorder()->revoke($contact, PreferenceDimension::Exercise, $exercise->id, null);

    $preference = TrainingPreference::where('contact_id', $contact->id)->first();
    expect($preference->status)->toBe(PreferenceStatus::Revoked);
    expect($preference->revoked_at)->not->toBeNull();
});

it('reactivates the SAME row on redeclaration after revocation — never a second row', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();

    $original = preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');
    preferenceRecorder()->revoke($contact, PreferenceDimension::Exercise, $exercise->id, null);
    $revokedAt = TrainingPreference::find($original->id)->revoked_at;

    $reactivated = preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees (de nuevo).');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
    expect($reactivated->id)->toBe($original->id);
    expect($reactivated->status)->toBe(PreferenceStatus::Active);
    expect($reactivated->reactivated_at)->not->toBeNull();
    // revoked_at se PRESERVA como historia de la última revocación — nunca se limpia.
    expect($reactivated->revoked_at->timestamp)->toBe($revokedAt->timestamp);
    // original_text refleja la declaración MÁS RECIENTE, no la primera.
    expect($reactivated->original_text)->toBe('No me gustan los burpees (de nuevo).');
});

it('full lifecycle example from the design doc: create -> duplicate -> revoke -> redeclare months later', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();
    $recorder = preferenceRecorder();

    // 1. "No me gustan los burpees."
    $recorder->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');
    // 2. se crea preference #1.
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
    // 3. vuelve a decir "No me gustan los burpees."
    $recorder->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');
    // 4. NO debe crear #2 activo.
    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
    // 5. revoca.
    $recorder->revoke($contact, PreferenceDimension::Exercise, $exercise->id, null);
    expect(TrainingPreference::where('contact_id', $contact->id)->first()->status)->toBe(PreferenceStatus::Revoked);
    // 6. meses después vuelve a decir "No me gustan los burpees."
    $recorder->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');

    expect(TrainingPreference::where('contact_id', $contact->id)->count())->toBe(1);
    expect(TrainingPreference::where('contact_id', $contact->id)->first()->status)->toBe(PreferenceStatus::Active);
});

it('supports the Equipment dimension independently from Exercise', function () {
    $contact = Contact::factory()->create();

    $preference = preferenceRecorder()->persistEquipmentPreference($contact, 'dumbbells', 'No me gusta usar mancuernas.');

    expect($preference->dimension)->toBe(PreferenceDimension::Equipment);
    expect($preference->equipment_value)->toBe('dumbbells');
    expect($preference->exercise_id)->toBeNull();
    expect($preference->preference_key)->toBe('equipment:dumbbells');
});

it('never allows two active rows for the same (contact, preference_key) — unique index enforced', function () {
    $contact = Contact::factory()->create();
    $exercise = Exercise::factory()->create();

    preferenceRecorder()->persistExercisePreference($contact, $exercise->id, 'No me gustan los burpees.');

    expect(fn () => TrainingPreference::create([
        'contact_id' => $contact->id,
        'dimension' => PreferenceDimension::Exercise,
        'exercise_id' => $exercise->id,
        'preference_key' => "exercise:{$exercise->id}",
        'status' => PreferenceStatus::Active,
        'original_text' => 'Duplicado manual.',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
