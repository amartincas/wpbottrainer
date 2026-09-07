<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TenantWizardForm
{
    // Persona templates for system prompts
    private static array $personaTemplates = [
        'vendedor' => 'You are a friendly sales assistant for a WhatsApp Bot Tenant. Your role is to help customers find the perfect solution for their business needs. Be helpful, positive, and focused on selling products that solve their problems.',
        'soporte' => 'You are a professional customer support agent. Your role is to resolve customer issues quickly and efficiently. Be empathetic, thorough, and always aim to exceed expectations.',
        'asesor' => 'You are a helpful business advisor. Your role is to provide guidance and recommendations to help clients make informed decisions. Be knowledgeable, friendly, and focus on understanding their needs.',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // === WEBHOOK CONFIGURATION ===
                TextInput::make('wa_verify_token')
                    ->label('Verify Token')
                    ->helperText('This is your webhook verify token. Use this in Meta Developers Console > Webhook Settings.')
                    ->reactive()
                    ->columnSpanFull(),

                TextInput::make('webhook_url')
                    ->label('Webhook URL')
                    //->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(function ($state, $record) {
                        if (!$record) {
                            return env('APP_URL') . '/api/whatsapp/webhook/{tenant-token}';
                        }
                        $token = $record->wa_verify_token;
                        $baseUrl = env('APP_URL');
                        return "{$baseUrl}/api/whatsapp/webhook/{$token}";
                    })
                    ->helperText('Copy this URL to Meta Developers Console > Webhook Settings > Callback URL')
                    ->columnSpanFull(),

                // === API CREDENTIALS ===
                TextInput::make('wa_phone_number_id')
                    ->label('Phone Number ID')
                    ->required()
                    ->helperText('Found in Meta > Settings > Phone Numbers. Format: 123456789')
                    ->placeholder('e.g., 123456789')
                    ->columnSpan(1),

                TextInput::make('wa_business_account_id')
                    ->label('WABA ID (Business Account)')
                    ->helperText('Found in Meta > Settings > Business Accounts. Format: waid_1234567890')
                    ->placeholder('e.g., waid_1234567890')
                    ->columnSpan(1),

                TextInput::make('wa_access_token')
                    ->label('Access Token')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Generate in Meta Developers > My Apps > System User Access Tokens')
                    ->placeholder('eaa...')
                    ->columnSpanFull(),

                // === REGIONAL (Hito 10) ===
                // Estructural, no opcional: WpbotTrainer debe poder operar en
                // múltiples países. Obligatorio en el wizard para que ningún
                // tenant nuevo quede sin configurar — ver
                // App\Training\Support\TimezoneResolver (nunca usa un
                // fallback silencioso en tiempo de ejecución).
                Select::make('timezone')
                    ->label('Timezone (IANA)')
                    ->searchable()
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->required()
                    ->default('America/Bogota')
                    ->rule('timezone:all')
                    ->helperText('Zona horaria del negocio — determina cuándo se disparan sus recordatorios. Ej: America/Bogota, America/Mexico_City, America/Lima, Europe/Madrid.')
                    ->columnSpanFull(),

                // === AI PERSONA ===
                Select::make('personality_type')
                    ->label('Persona Type')
                    ->options([
                        'vendedor' => 'Sales Representative (Vendedor)',
                        'soporte' => 'Support Agent (Soporte)',
                        'asesor' => 'Business Advisor (Asesor)',
                    ])
                    ->required()
                    ->helperText('Select the personality that best matches your use case.')
                    ->reactive()
                    ->columnSpan(1),

                Select::make('ai_provider')
                    ->label('AI Provider')
                    ->options([
                        'openai' => 'ChatGPT (OpenAI)',
                        'grok' => 'Grok',
                        'gemini' => 'Gemini',
                    ])
                    ->default('openai')
                    ->required()
                    ->reactive()
                    ->helperText('Select your AI provider')
                    ->columnSpan(1),

                Select::make('ai_model')
                    ->label('AI Model')
                    ->options(function (Get $get) {
                        $provider = $get('ai_provider');
                        if (!$provider) {
                            return [];
                        }

                        $models = config("ai.models.{$provider}", []);
                        return array_combine($models, $models);
                    })
                    ->default('gpt-4o')
                    ->required()
                    ->reactive()
                    ->live()
                    ->helperText('Choose the model for your AI responses.')
                    ->columnSpanFull(),

                TextInput::make('ai_api_key')
                    ->label('OpenAI API Key')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Your OpenAI API key from https://platform.openai.com/api-keys (encrypted in database)')
                    ->placeholder('sk-...')
                    ->columnSpanFull(),

                Textarea::make('system_prompt')
                    ->label('System Prompt')
                    ->required()
                    ->rows(6)
                    ->helperText('This prompt defines how the AI behaves. It was auto-filled based on your persona selection.')
                    ->columnSpanFull()
                    ->default(function (Get $get) {
                        $personality = $get('personality_type');
                        return self::$personaTemplates[$personality] ?? '';
                    })
                    ->reactive(),
            ]);
    }
}
