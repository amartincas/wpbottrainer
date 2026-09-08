<?php

namespace App\Filament\Resources\MembershipPlan\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 11 — catálogo pequeño, tenant-scoped, sin campos de facturación
 * recurrente (sin ciclo, sin fecha de renovación, sin proration). Nunca
 * edita `Payment`s ya creados — cambiar un plan aquí no altera ningún
 * Payment histórico que ya lo haya referenciado (snapshot congelado, ver
 * App\Models\Payment).
 */
class MembershipPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([

            Select::make('tenant_id')
                ->label('Tenant')
                ->relationship(
                    'tenant',
                    'name',
                    fn ($query) => $query->when(
                        ! Auth::user()?->is_super_admin,
                        fn ($q) => $q->where('id', Auth::user()?->tenant_id)
                    )
                )
                ->required()
                ->default(Auth::user()?->tenant_id)
                ->searchable()
                ->preload(),

            TextInput::make('label')
                ->label('Nombre')
                ->placeholder('ej: 3 meses')
                ->helperText('Texto libre que ve el cliente por WhatsApp al elegir su membresía.')
                ->required()
                ->maxLength(100),

            TextInput::make('duration_months')
                ->label('Duración (meses)')
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText('Entero abierto — no está limitado a 1/3/6/12, puede ser cualquier cantidad de meses.'),

            TextInput::make('price')
                ->label('Precio')
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('$'),

            TextInput::make('currency')
                ->label('Moneda')
                ->placeholder('COP')
                ->default('COP')
                ->required()
                ->maxLength(10),

            Toggle::make('is_active')
                ->label('Activo')
                ->helperText('Un plan inactivo no aparece en el menú de WhatsApp ni puede seleccionarse — pero los Payments que ya lo compraron no se ven afectados.')
                ->onColor('success')
                ->offColor('gray')
                ->default(true)
                ->inline(false),

        ]);
    }
}
