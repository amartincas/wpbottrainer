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
    'currency',
    'country',
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
        ];
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
