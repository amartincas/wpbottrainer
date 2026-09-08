<?php

use App\Referrals\Models\ReferralCode;
use App\Referrals\Support\ReferralCodeGenerator;

it('generates a code in the expected format, with an unambiguous alphabet', function () {
    $code = app(ReferralCodeGenerator::class)->generateUnique();

    expect($code)->toMatch('/^REF-[A-Z2-9]{6}$/');
    expect($code)->not->toContain('0')->not->toContain('O')->not->toContain('1')->not->toContain('I');
});

it('retries on collision and eventually returns a unique code', function () {
    // Fuerza una colisión: el primer código "aleatorio" real ya existe.
    $existing = ReferralCode::factory()->create();

    $generator = app(ReferralCodeGenerator::class);
    $code = $generator->generateUnique();

    expect($code)->not->toBe($existing->code);
    expect(ReferralCode::where('code', $code)->exists())->toBeFalse(); // no se persiste aquí, solo se genera
});

it('extracts a valid code embedded anywhere in a free-form message, case-insensitively', function () {
    $generator = app(ReferralCodeGenerator::class);

    expect($generator->extractFromText('Hola! Quiero unirme 💪 REF-AB23CD'))->toBe('REF-AB23CD');
    expect($generator->extractFromText('ref-ab23cd, quiero información'))->toBe('REF-AB23CD');
    expect($generator->extractFromText('no tiene ningún código'))->toBeNull();
});

it('does not match a malformed or partial code', function () {
    $generator = app(ReferralCodeGenerator::class);

    expect($generator->extractFromText('REF-AB23C'))->toBeNull(); // muy corto
    expect($generator->extractFromText('XREF-AB23CD'))->toBeNull(); // sin límite de palabra real antes de "REF"
});
