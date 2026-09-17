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

                // P1-A — nudge de ejercicio no reportado: configuración de
                // negocio simple por Tenant, mismo criterio que "Programa de
                // Referidos" arriba (columnas planas en tenants, sin tabla
                // propia).
                Section::make('Nudge de ejercicio no reportado')
                    ->columns(2)
                    ->schema([
                        Toggle::make('exercise_nudge_enabled')
                            ->label('Nudge activo')
                            ->default(true)
                            ->helperText('Desactivarlo detiene nuevos nudges — nunca afecta uno ya enviado.'),
                        TextInput::make('exercise_nudge_after_minutes')
                            ->label('Minutos sin reportar antes del nudge')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(30)
                            ->helperText('Tiempo desde que se entrega un ejercicio (sin reporte todavía) antes de enviar un recordatorio único por ese ejercicio.'),
                    ]),

                // Duración objetivo APROXIMADA de sesión — configuración de
                // negocio simple por Tenant, mismo criterio que las
                // secciones anteriores. 15-90 es un guard rail de
                // configuración, no una regla de entrenamiento — ver
                // App\Training\Engine\TrainingEngine::exercisesForTargetDuration().
                //
                // ->default(30) aquí NO es una segunda fuente de verdad del
                // default de negocio — la única fuente de verdad es la
                // columna en la migración (2026_09_17_000002_...). Este
                // default es solo una conveniencia del formulario de
                // creación de Filament (pre-rellena el campo para que no
                // quede vacío en el form); en edición Filament siempre lee
                // el valor real ya persistido, nunca este número. Mismo
                // patrón exacto, sin excepción, que ->default(3) en
                // referral_reward_days y ->default(30) en
                // exercise_nudge_after_minutes más arriba en este archivo —
                // si el default de negocio cambia, se cambia en la migración
                // y aquí, igual que ya se hace con esos dos campos.
                Section::make('Duración objetivo de la sesión')
                    ->schema([
                        TextInput::make('target_session_duration_minutes')
                            ->label('Duración objetivo (minutos)')
                            ->numeric()
                            ->required()
                            ->minValue(15)
                            ->maxValue(90)
                            ->default(30)
                            ->helperText('Duración APROXIMADA de la sesión — no es un máximo estricto ni una duración exacta. El sistema calcula cuántos ejercicios incluir en base a este valor.'),
                    ]),
            ]);
    }
}
