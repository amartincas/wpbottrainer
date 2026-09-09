<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'personality_type',
    'system_prompt',
    'ai_provider',
    'ai_model',
    'ai_api_key',
    'openai_transcription_api_key',
    'wa_access_token',
    'wa_phone_number_id',
    'wa_business_account_id',
    'wa_verify_token',
    'wa_display_phone_number',
    'referral_reward_days',
    'referral_program_enabled',
    'trial_duration_days',
    'currency',
    'country',
    'timezone',
    'monthly_price',
    'payment_instructions',
    'nequi_number',
    'daviplata_number',
    'gateway_provider',
    'gateway_config',
])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'personality_type' => 'string',
            'ai_provider' => 'string',
            'ai_api_key' => 'encrypted',
            'openai_transcription_api_key' => 'encrypted',
            'wa_access_token' => 'encrypted',
            'wa_verify_token' => 'encrypted',
            'monthly_price' => 'decimal:2',
            // gateway_config puede llevar credenciales de una pasarela real
            // en el futuro — cifrado igual que ai_api_key/wa_access_token,
            // aunque hoy (sin pasarela integrada) normalmente esté vacío.
            'gateway_config' => 'encrypted',
            // Hito 13 — configuración del programa de Referidos, tenant-scoped
            // (mismo criterio que monthly_price/nequi_number para Payments).
            'referral_reward_days' => 'integer',
            'referral_program_enabled' => 'boolean',
            // Hito 15 — mismo criterio que referral_reward_days.
            'trial_duration_days' => 'integer',
        ];
    }

    /**
     * Hito 10 — defensa en profundidad: `timezone` se valida en el
     * formulario de Filament (`Select::make('timezone')->rule('timezone:all')`),
     * pero cualquier otra vía de escritura (seeders, comandos, una futura
     * API) debe encontrar la misma barrera. Nunca corrige el valor por su
     * cuenta ni lo sustituye por un default — un valor inválido bloquea el
     * guardado, punto.
     */
    protected static function booted(): void
    {
        static::saving(function (self $tenant) {
            if ($tenant->timezone !== null && ! in_array($tenant->timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException("Invalid IANA timezone for Tenant: {$tenant->timezone}");
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
