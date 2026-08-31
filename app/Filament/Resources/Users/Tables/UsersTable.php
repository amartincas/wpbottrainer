<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('tenant.name')
                    ->label('Tenant Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                ToggleColumn::make('is_super_admin')
                    ->label('Super Admin')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->label('Tenant'),
                SelectFilter::make('is_super_admin')
                    ->options([
                        1 => 'Super Admins',
                        0 => 'Regular Users',
                    ])
                    ->label('User Type'),
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
