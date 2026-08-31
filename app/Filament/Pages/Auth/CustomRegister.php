<?php

namespace App\Filament\Pages\Auth;

use App\Models\Tenant;
use App\Models\User;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomRegister extends Register
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getTenantNameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getTenantNameFormComponent(): Component
    {
        return TextInput::make('tenant_name')
            ->label(__('Nombre del negocio'))
            ->hint(__('El nombre de tu negocio en WpbotTrainer'))
            ->required()
            ->maxLength(255);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        return $this->wrapInDatabaseTransaction(function () use ($data) {
            // 1. Create the Tenant with defaults
            $tenant = Tenant::create([
                'name' => $data['tenant_name'],
                'personality_type' => 'asesor',
                'system_prompt' => 'You are a helpful assistant.',
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-4o',
                'wa_access_token' => null,
                'wa_phone_number_id' => null,
                'wa_business_account_id' => null,
                'ai_api_key' => null,
                'wa_verify_token' => Str::random(32),
            ]);

            // 2. Remove tenant_name from user data and add tenant_id
            unset($data['tenant_name']);
            $data['tenant_id'] = $tenant->id;
            $data['is_super_admin'] = false;

            // 3. Create the User and assign to the tenant
            return User::create($data);
        });
    }
}
