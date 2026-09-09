<?php

namespace App\Filament\Resources\Faq\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 14 — CRUD tenant-scoped, mismo criterio de autorización que
 * `MembershipPlanResource`: cualquier admin autenticado puede gestionar
 * las FAQs de SU tenant, no exclusivo de superadmin (contenido, no una
 * operación sensible como confirmar un pago).
 */
class FaqForm
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

            TextInput::make('question')
                ->label('Pregunta')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('answer')
                ->label('Respuesta')
                ->required()
                ->rows(4)
                ->columnSpanFull()
                ->helperText('Esta es la fuente de conocimiento autorizada — la IA redacta la respuesta final al usuario grounded exclusivamente en este texto, nunca lo envía literalmente ni agrega información que no esté aquí.'),
            Toggle::make('is_active')
                ->label('Activa')
                ->default(true),
        ]);
    }
}
