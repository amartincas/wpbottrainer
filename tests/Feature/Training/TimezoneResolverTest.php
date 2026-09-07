<?php

use App\Models\Contact;
use App\Models\Tenant;
use App\Training\Support\InvalidTenantTimezoneException;
use App\Training\Support\TimezoneResolver;
use Illuminate\Support\Facades\DB;

function timezoneResolver(): TimezoneResolver
{
    return new TimezoneResolver;
}

it('resolves the Colombia tenant timezone correctly', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Bogota']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);

    expect(timezoneResolver()->resolve($contact))->toBe('America/Bogota');
});

it('resolves a different tenant timezone correctly, distinct from Colombia', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);

    expect(timezoneResolver()->resolve($contact))->toBe('Europe/Madrid');
    expect(timezoneResolver()->resolve($contact))->not->toBe('America/Bogota');
});

it('throws for an invalid timezone value, never silently falling back to America/Bogota', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Bogota']);
    // Dato corrupto insertado directamente, sin pasar por el guard del
    // modelo/Filament — simula una fila legada o una escritura fuera de la
    // vía normal.
    $tenant->forceFill(['timezone' => 'Not/AZone'])->saveQuietly();
    $contact = Contact::factory()->create(['tenant_id' => $tenant->id]);

    expect(fn () => timezoneResolver()->resolve($contact))
        ->toThrow(InvalidTenantTimezoneException::class);
});

it('a Tenant can never end up without a timezone — the column itself is NOT NULL, no legacy-null path exists', function () {
    // Bloque 10, sección 8: NOT NULL a nivel de columna, no solo a nivel de
    // aplicación — ni siquiera un UPDATE crudo que bypasee Eloquent (y por
    // tanto el guard del modelo) puede dejar la fila sin timezone. Esta es
    // la prueba más directa y honesta de "nunca queda sin configurar": no
    // hay ninguna vía, ni siquiera de bajo nivel, para producir ese estado.
    $tenant = Tenant::factory()->create(['timezone' => 'America/Bogota']);

    expect(fn () => DB::table('tenants')->where('id', $tenant->id)->update(['timezone' => null]))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect($tenant->fresh()->timezone)->toBe('America/Bogota');
});

it('TimezoneResolver still guards defensively if a Contact somehow has no resolvable Tenant relation', function () {
    // Defensa en profundidad: aunque hoy la FK de contacts.tenant_id y el
    // NOT NULL de tenants.timezone hacen este caso inalcanzable por las
    // vías normales, TimezoneResolver no asume esa garantía — nunca
    // devuelve un default silencioso si, por lo que sea, ->tenant resuelve
    // a null.
    $contact = Contact::factory()->make(['tenant_id' => 999999]); // no persistido, sin Tenant real

    expect(fn () => timezoneResolver()->resolve($contact))
        ->toThrow(InvalidTenantTimezoneException::class);
});
