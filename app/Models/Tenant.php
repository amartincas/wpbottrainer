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
