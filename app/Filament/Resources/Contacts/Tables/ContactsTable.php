<?php

namespace App\Filament\Resources\Contacts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant Name')
                    ->searchable()
                    ->sortable()
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('customer_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_phone')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('product_service_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('summary')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        return $column->getState();
                    })
                    ->wrap(),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
                ToggleColumn::make('is_processed')
                    ->label('Processed')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_processed')
                    ->label('Processed Status')
                    ->placeholder('All')
                    ->trueLabel('Processed')
                    ->falseLabel('Not Processed'),
                SelectFilter::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->label('Tenant'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
