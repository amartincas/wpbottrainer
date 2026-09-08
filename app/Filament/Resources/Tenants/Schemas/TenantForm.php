<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required(),
                Select::make('personality_type')
                    ->options(['vendedor' => 'Vendedor', 'soporte' => 'Soporte', 'asesor' => 'Asesor'])
                    ->required(),
                Textarea::make('system_prompt')
                    ->required()
                    ->columnSpanFull(),
                Select::make('ai_provider')
                    ->options(['openai' => 'Openai', 'grok' => 'Grok', 'gemini' => 'Gemini'])
                    ->required()
                    ->rule('in:openai,grok,gemini')
                    ->reactive(),
                Select::make('ai_model')
                    ->label('AI Model')
                    ->required()
                    ->rule('string')
                    ->options(function (Get $get) {
                        $provider = $get('ai_provider');
                        if (!$provider) {
                            return [];
                        }

                        $models = config("ai.models.{$provider}", []);
                        return array_combine($models, $models);
                    })
                    ->reactive(),
                TextInput::make('ai_api_key')
                    ->label('AI API Key')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('string')
                    ->rule('min:20')
                    ->columnSpanFull()
                    ->helperText('API key for the selected AI provider (encrypted). Must be at least 20 characters'),
                TextInput::make('wa_phone_number_id')
                    ->label('Phone Number ID')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('wa_business_account_id')
                    ->label('WABA ID (Business Account)')
                    ->columnSpanFull(),
                TextInput::make('wa_access_token')
                    ->label('Access Token')
                    ->password()
                    ->revealable()
                    ->required()
                    ->columnSpanFull()
                    ->helperText('WhatsApp Business API access token from Meta'),
                TextInput::make('wa_verify_token')
                    ->label('Verify Token')
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Verify token for webhook setup'),
                // Hito 10 — propiedad ESTRUCTURAL, no opcional: WpbotTrainer
                // debe poder operar en múltiples países. Identificador IANA
                // (nunca un offset fijo), validado con la regla nativa
                // `timezone` de Laravel. 'America/Bogota' es solo el valor
                // inicial sugerido al crear un Tenant — visible y editable,
                // nunca un fallback oculto (ver App\Training\Support\TimezoneResolver,
                // que nunca usa este valor por defecto en tiempo de ejecución).
                Select::make('timezone')
                    ->label('Timezone (IANA)')
                    ->searchable()
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->required()
                    ->default('America/Bogota')
                    ->rule('timezone:all')
                    ->helperText('Zona horaria del negocio — determina cuándo se disparan sus recordatorios (Hito 10). Ej: America/Bogota, America/Mexico_City, America/Lima, Europe/Madrid.'),

                // Hito 13 — configuración del programa de Referidos.
                // wa_display_phone_number es genérico de WhatsApp (el
                // número marcable real, distinto de wa_phone_number_id),
                // necesario para construir el link de invitación wa.me.
                Section::make('Programa de Referidos')
                    ->columns(2)
                    ->schema([
                        TextInput::make('wa_display_phone_number')
                            ->label('Número de WhatsApp marcable')
                            ->helperText('Formato internacional sin "+" (ej: 573001234567) — usado para construir el link de invitación wa.me. Si se deja vacío, la invitación se entrega solo como texto para reenviar.')
                            ->columnSpanFull(),
                        TextInput::make('referral_reward_days')
                            ->label('Días de recompensa por referido')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(3)
                            ->helperText('Cambiar este valor nunca afecta recompensas ya otorgadas — cada una queda congelada con el valor vigente al momento de generarse.'),
                        Toggle::make('referral_program_enabled')
                            ->label('Programa activo')
                            ->default(true)
                            ->helperText('Desactivarlo detiene nuevas atribuciones — nunca invalida atribuciones ya existentes.'),
                    ]),
            ]);
    }
}
