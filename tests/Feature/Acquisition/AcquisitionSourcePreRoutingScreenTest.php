<?php

use App\Acquisition\Enums\AcquisitionSource;
use App\Acquisition\Models\ContactAcquisition;
use App\Acquisition\Support\AcquisitionSourcePreRoutingScreen;
use App\Core\Messaging\ExecutionContext;
use App\Core\Messaging\Ingest;
use App\Core\Messaging\IngestedMessage;
use App\Models\Contact;
use App\Models\Tenant;
use App\Referrals\Models\Referral;
use Illuminate\Support\Facades\DB;

/**
 * P1-B — atribución de adquisición. `screen()` SIEMPRE debe retornar
 * `false` (nunca reclama el pipeline) — se verifica explícitamente, no solo
 * el efecto sobre `ContactAcquisition`. Nombres de helpers con prefijo
 * "acquisition" — propios de este archivo, mismo criterio que el resto de
 * la suite para evitar colisión de funciones globales entre archivos de
 * test (Pest ejecuta todos los archivos en el mismo proceso PHP;
 * `tests/Feature/Referrals/ReferralAttributionPreRoutingScreenTest.php` ya
 * define sus propias `screen()`/`referralContext()` globales).
 */

function acquisitionContext(Tenant $tenant, string $from, ?array $referral = null): ExecutionContext
{
    return new ExecutionContext(
        tenant: $tenant,
        conversation: null,
        message: new IngestedMessage($from, 'Hola', 'wamid-test', 'text', null, $referral),
    );
}

function acquisitionScreen(): AcquisitionSourcePreRoutingScreen
{
    return app(AcquisitionSourcePreRoutingScreen::class);
}

/**
 * Objeto `referral` completo, con todos los campos documentados por Meta
 * para Click-to-WhatsApp — ver auditoría P1-B.
 */
function fullMetaReferral(array $overrides = []): array
{
    return array_merge([
        'source_id' => 'AD-123456',
        'source_type' => 'ad',
        'source_url' => 'https://fb.me/some-ad',
        'ctwa_clid' => 'CLID-ABC-789',
        'headline' => 'Baja de peso entrenando desde casa',
        'body' => 'Rutinas personalizadas por WhatsApp',
        'media_type' => 'image',
        'image_url' => 'https://scontent.example/image.jpg',
        'video_url' => 'https://scontent.example/video.mp4',
        'thumbnail_url' => 'https://scontent.example/thumb.jpg',
    ], $overrides);
}

// ── 1: referral con source_id crea meta_ads ──

it('1: a referral with source_id present creates ContactAcquisition with source=meta_ads', function () {
    $tenant = Tenant::factory()->create();

    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000001', ['source_id' => 'AD-1']));

    expect($result)->toBeFalse();
    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000001')->sole();
    $acquisition = ContactAcquisition::where('contact_id', $contact->id)->sole();
    expect($acquisition->source)->toBe(AcquisitionSource::MetaAds);
    expect($acquisition->meta_ad_id)->toBe('AD-1');
});

// ── 2/3: referral completo — todos los campos + raw_referral_payload exacto ──

it('2/3: a full referral persists every mapped field and preserves the exact raw payload', function () {
    $tenant = Tenant::factory()->create();
    $referral = fullMetaReferral();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000002', $referral));

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000002')->sole();
    $acquisition = ContactAcquisition::where('contact_id', $contact->id)->sole();

    expect($acquisition->source)->toBe(AcquisitionSource::MetaAds);
    expect($acquisition->meta_ad_id)->toBe($referral['source_id']);
    expect($acquisition->meta_ctwa_clid)->toBe($referral['ctwa_clid']);
    expect($acquisition->meta_source_type)->toBe($referral['source_type']);
    expect($acquisition->meta_headline)->toBe($referral['headline']);
    expect($acquisition->meta_body)->toBe($referral['body']);
    // image_url tiene prioridad sobre video_url/thumbnail_url (ver
    // AcquisitionSourcePreRoutingScreen::buildMetaAdsAttributes()).
    expect($acquisition->meta_media_url)->toBe($referral['image_url']);
    // toEqual() (comparación laxa, sin importar el orden de las claves) —
    // el JSON reconstruido desde BD conserva los mismos pares clave/valor,
    // pero el orden de claves no está garantizado tras el viaje por
    // json_encode/decode del cast `array`; el orden nunca es significativo
    // aquí, solo que el contenido sea exactamente el mismo.
    expect($acquisition->raw_referral_payload)->toEqual($referral);
});

it('meta_media_url falls back to video_url when image_url is absent, and to thumbnail_url when neither image nor video exist', function () {
    $tenant = Tenant::factory()->create();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000020', fullMetaReferral(['image_url' => null])));
    $contactA = Contact::where('customer_phone', '573000000020')->sole();
    expect(ContactAcquisition::where('contact_id', $contactA->id)->sole()->meta_media_url)->toBe('https://scontent.example/video.mp4');

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000021', fullMetaReferral(['image_url' => null, 'video_url' => null])));
    $contactB = Contact::where('customer_phone', '573000000021')->sole();
    expect(ContactAcquisition::where('contact_id', $contactB->id)->sole()->meta_media_url)->toBe('https://scontent.example/thumb.jpg');
});

// ── 4: referral parcial — solo source_id, resto NULL, sin fallar ──

it('4: a partial referral (only source_id) still classifies as meta_ads, leaving absent fields NULL, without throwing', function () {
    $tenant = Tenant::factory()->create();

    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000004', ['source_id' => 'AD-PARTIAL']));

    expect($result)->toBeFalse();
    $contact = Contact::where('customer_phone', '573000000004')->sole();
    $acquisition = ContactAcquisition::where('contact_id', $contact->id)->sole();
    expect($acquisition->source)->toBe(AcquisitionSource::MetaAds);
    expect($acquisition->meta_ad_id)->toBe('AD-PARTIAL');
    expect($acquisition->meta_ctwa_clid)->toBeNull();
    expect($acquisition->meta_source_type)->toBeNull();
    expect($acquisition->meta_headline)->toBeNull();
    expect($acquisition->meta_body)->toBeNull();
    expect($acquisition->meta_media_url)->toBeNull();
    expect($acquisition->raw_referral_payload)->toBe(['source_id' => 'AD-PARTIAL']);
});

it('classifies as meta_ads even when referral carries no source_id at all — presence of the object is the signal, not any specific field', function () {
    $tenant = Tenant::factory()->create();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000005', ['ctwa_clid' => 'CLID-ONLY']));

    $contact = Contact::where('customer_phone', '573000000005')->sole();
    $acquisition = ContactAcquisition::where('contact_id', $contact->id)->sole();
    expect($acquisition->source)->toBe(AcquisitionSource::MetaAds);
    expect($acquisition->meta_ad_id)->toBeNull();
    expect($acquisition->meta_ctwa_clid)->toBe('CLID-ONLY');
});

// ── 5: sin referral, con Referral existente -> source=referral ──

it('5: with no Meta referral but an existing Referral for the contact, creates source=referral with the correct referral_id', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000006']);
    $referral = Referral::create([
        'referred_contact_id' => $invited->id,
        'referrer_contact_id' => $referrer->id,
        'code' => 'REF-TEST01',
    ]);

    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000006', null));

    expect($result)->toBeFalse();
    $acquisition = ContactAcquisition::where('contact_id', $invited->id)->sole();
    expect($acquisition->source)->toBe(AcquisitionSource::Referral);
    expect($acquisition->referral_id)->toBe($referral->id);
});

// ── 6: sin referral, sin Referral -> organic ──

it('6: with neither a Meta referral nor an existing Referral, creates source=organic', function () {
    $tenant = Tenant::factory()->create();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000007', null));

    $contact = Contact::where('customer_phone', '573000000007')->sole();
    $acquisition = ContactAcquisition::where('contact_id', $contact->id)->sole();
    expect($acquisition->source)->toBe(AcquisitionSource::Organic);
    expect($acquisition->meta_ad_id)->toBeNull();
    expect($acquisition->referral_id)->toBeNull();
});

// ── 7/8: first-touch — nunca se sobrescribe, en ningún sentido ──

it('7: first-touch — a Contact already attributed to meta_ads is never changed by a later, different referral', function () {
    $tenant = Tenant::factory()->create();
    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000008', ['source_id' => 'AD-FIRST']));
    $contact = Contact::where('customer_phone', '573000000008')->sole();
    $original = ContactAcquisition::where('contact_id', $contact->id)->sole();

    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000008', ['source_id' => 'AD-SECOND', 'ctwa_clid' => 'CLID-SECOND']));

    expect($result)->toBeFalse();
    expect(ContactAcquisition::where('contact_id', $contact->id)->count())->toBe(1);
    $stillOriginal = ContactAcquisition::where('contact_id', $contact->id)->sole();
    expect($stillOriginal->id)->toBe($original->id);
    expect($stillOriginal->meta_ad_id)->toBe('AD-FIRST'); // nunca AD-SECOND
    expect($stillOriginal->meta_ctwa_clid)->toBeNull(); // nunca CLID-SECOND
});

it('8: first-touch — a Contact already attributed to referral stays referral even if a Meta ad referral arrives later', function () {
    $tenant = Tenant::factory()->create();
    $referrer = Contact::factory()->create(['tenant_id' => $tenant->id]);
    $invited = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000009']);
    $referral = Referral::create(['referred_contact_id' => $invited->id, 'referrer_contact_id' => $referrer->id, 'code' => 'REF-TEST02']);
    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000009', null));
    expect(ContactAcquisition::where('contact_id', $invited->id)->sole()->source)->toBe(AcquisitionSource::Referral);

    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000009', ['source_id' => 'AD-TOO-LATE']));

    expect($result)->toBeFalse();
    expect(ContactAcquisition::where('contact_id', $invited->id)->count())->toBe(1);
    $stillReferral = ContactAcquisition::where('contact_id', $invited->id)->sole();
    expect($stillReferral->source)->toBe(AcquisitionSource::Referral);
    expect($stillReferral->referral_id)->toBe($referral->id);
});

// ── 9: concurrencia / índice único ──

it('9a: the database genuinely rejects a second ContactAcquisition row for the same contact_id (SQLSTATE 23000)', function () {
    // Prueba directa del ÚNICO mecanismo real de first-touch bajo
    // concurrencia genuina: el índice UNIQUE de la migración. Un test de
    // PHP de un solo hilo no puede simular dos procesos realmente
    // simultáneos (mismo criterio ya aceptado en
    // ReferralAttributionPreRoutingScreenTest.php, que tampoco lo simula);
    // esto SÍ prueba, contra la base de datos real, que el constraint
    // existe y funciona — el fundamento del que depende el catch de
    // AcquisitionSourcePreRoutingScreen::createAcquisition().
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);
    ContactAcquisition::create(['contact_id' => $contact->id, 'source' => AcquisitionSource::Organic]);

    expect(fn () => DB::table('contact_acquisitions')->insert([
        'contact_id' => $contact->id,
        'source' => 'meta_ads',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(ContactAcquisition::where('contact_id', $contact->id)->count())->toBe(1);
});

it('9b: a second screen() call for the same Contact never throws and leaves exactly one row (idempotent no-op)', function () {
    $tenant = Tenant::factory()->create();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000010', ['source_id' => 'AD-RACE']));
    $result = acquisitionScreen()->screen(acquisitionContext($tenant, '573000000010', ['source_id' => 'AD-RACE']));

    expect($result)->toBeFalse();
    $contact = Contact::where('customer_phone', '573000000010')->sole();
    expect(ContactAcquisition::where('contact_id', $contact->id)->count())->toBe(1);
});

// ── 10: screen() siempre retorna false ──

it('10: screen() always returns false, regardless of the resulting source', function () {
    $tenant = Tenant::factory()->create();

    expect(acquisitionScreen()->screen(acquisitionContext($tenant, '573000000011', ['source_id' => 'AD-X'])))->toBeFalse();
    expect(acquisitionScreen()->screen(acquisitionContext($tenant, '573000000012', null)))->toBeFalse();
    // Contact ya atribuido — sigue retornando false.
    expect(acquisitionScreen()->screen(acquisitionContext($tenant, '573000000011', ['source_id' => 'AD-Y'])))->toBeFalse();
});

// ── 11: el screen crea el Contact cuando todavía no existe ──

it('11: creates the Contact when it does not exist yet, using the same defensive pattern as ReferralAttributionPreRoutingScreen', function () {
    $tenant = Tenant::factory()->create();
    expect(Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000013')->exists())->toBeFalse();

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000013', ['source_id' => 'AD-NEW']));

    $contact = Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000013')->sole();
    expect($contact->bot_active)->toBeTrue();
});

it('reuses an already-existing Contact instead of creating a duplicate', function () {
    $tenant = Tenant::factory()->create();
    $existing = Contact::factory()->create(['tenant_id' => $tenant->id, 'customer_phone' => '573000000014']);

    acquisitionScreen()->screen(acquisitionContext($tenant, '573000000014', ['source_id' => 'AD-EXISTING']));

    expect(Contact::where('tenant_id', $tenant->id)->where('customer_phone', '573000000014')->count())->toBe(1);
    expect(ContactAcquisition::where('contact_id', $existing->id)->exists())->toBeTrue();
});

// ── 12 (parte Ingest -> IngestedMessage): el referral viaja tal cual ──

it('12: Ingest::process() propagates $referral verbatim into the resulting IngestedMessage', function () {
    $tenant = Tenant::factory()->create();
    $referral = fullMetaReferral();

    $ingested = app(Ingest::class)->process($tenant, '573000000015', 'Hola', 'wamid-x', 'text', null, $referral);

    expect($ingested)->not->toBeNull();
    expect($ingested->referral)->toBe($referral);
});

it('Ingest::process() defaults $referral to null when the caller does not pass one, and IngestedMessage reflects it', function () {
    $tenant = Tenant::factory()->create();

    $ingested = app(Ingest::class)->process($tenant, '573000000016', 'Hola', 'wamid-y', 'text', null);

    expect($ingested->referral)->toBeNull();
});
