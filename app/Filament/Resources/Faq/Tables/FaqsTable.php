<?php

namespace App\Filament\Resources\Faq\Tables;

use App\Models\Tenant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label('Pregunta')
                    ->searchable()
                    ->limit(60),

                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                ToggleColumn::make('is_active')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(function (): array {
                        if (Auth::user()?->is_super_admin) {
                            return Tenant::orderBy('name')->pluck('name', 'id')->toArray();
                        }

                        return Tenant::where('id', Auth::user()?->tenant_id)->pluck('name', 'id')->toArray();
                    })
                    ->placeholder('Todos los tenants'),

                SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options(['1' => 'Activa', '0' => 'Inactiva'])
                    ->placeholder('Todas'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
