<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
            ]);
    }
}
