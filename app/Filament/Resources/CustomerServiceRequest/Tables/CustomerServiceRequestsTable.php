<?php

namespace App\Filament\Resources\CustomerServiceRequest\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Hito 14 — solo lectura: es un hecho histórico ya ocurrido (append-only),
 * nunca algo que se cree/edite a mano desde el panel — mismo criterio que
 * `PaymentResource`/`ReferralResource`.
 */
class CustomerServiceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contact.customer_name')->label('Cliente')->searchable()->placeholder('Sin nombre'),
                TextColumn::make('contact.customer_phone')->label('WhatsApp')->searchable(),
                TextColumn::make('contact.tenant.name')->label('Tenant')->badge()->color('info'),
                TextColumn::make('message')->label('Mensaje')->limit(60)->searchable(),
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
