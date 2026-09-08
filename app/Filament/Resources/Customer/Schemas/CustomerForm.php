<?php

namespace App\Filament\Resources\Customer\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Hito 12 — edición deliberadamente ACOTADA (ver docs/DECISIONS.md,
 * sección "Edición"): solo identidad de `Contact`. `TrainingProfile` es
 * de solo lectura desde Cliente en este hito (su editor real es el propio
 * flujo conversacional de onboarding); `TrainingAccess` nunca se edita
 * vía formulario genérico — solo mediante las 5 acciones administrativas
 * explícitas (`ViewCustomer::getHeaderActions()`); `Payment`/salud nunca
 * se editan desde aquí.
 */
class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('customer_name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),

            TextInput::make('customer_phone')
                ->label('WhatsApp')
                ->tel()
                ->required()
                ->maxLength(30),
        ]);
    }
}
