<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Contact;
use App\Models\User;
use App\Training\Enums\SafetyStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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

                // Hito 7.1 (propuesto) / Hito 8 (implementado): única forma
                // de desbloquear un TrainingProfile flagged_for_review —
                // siempre un humano autorizado, con nota obligatoria, nunca
                // el LLM ni automático. Ver TrainingProfile::clearSafetyFlag().
                Action::make('reviewSafety')
                    ->label('Revisar seguridad')
                    ->color('warning')
                    ->icon('heroicon-o-shield-exclamation')
                    ->visible(fn (Contact $record): bool => Auth::user()?->is_super_admin
                        && $record->trainingProfile?->safety_status === SafetyStatus::FlaggedForReview)
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('note')
                            ->label('Observación (obligatoria) — por qué es seguro que continúe')
                            ->required(),
                    ])
                    ->action(function (Contact $record, array $data): void {
                        /** @var User $user */
                        $user = Auth::user();
                        $record->trainingProfile?->clearSafetyFlag($user, $data['note']);

                        Notification::make()->title('Perfil desbloqueado')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
