<?php

namespace App\Filament\Resources\Customer\Schemas;

use App\Models\Contact;
use App\Payments\Enums\PaymentStatus;
use App\Training\Enums\TrainingAccessAuditAction;
use App\Training\Enums\TrainingAccessStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Hito 12 — vista de detalle de "Cliente". Ninguna sección aquí es
 * editable (eso vive exclusivamente en `CustomerForm`, acotado a
 * identidad, y en las 5 acciones administrativas de `ViewCustomer`) — este
 * Infolist es deliberadamente de solo lectura, incluida la sección
 * "Historial de acceso" (`TrainingAccessAudit`, append-only, ver
 * docs/DECISIONS.md).
 */
class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cliente')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('customer_name')->label('Nombre')->placeholder('Sin nombre'),
                        TextEntry::make('customer_phone')->label('WhatsApp')->copyable(),
                        TextEntry::make('tenant.name')->label('Tenant'),
                        TextEntry::make('created_at')->label('Cliente desde')->dateTime(),
                    ]),

                // Estado EFECTIVO (`effectiveStatus()`) y estado crudo
                // persistido se muestran ambos a propósito: el admin debe
                // poder ver que un `active` con `expires_at` pasado se
                // exhibe como "Expirado" sin que la fila técnica haya sido
                // mutada (ver docs/DECISIONS.md, sección Expired).
                Section::make('Membresía y acceso')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('access_effective_status')
                            ->label('Estado (efectivo)')
                            ->state(fn (Contact $record) => $record->trainingAccess?->effectiveStatus())
                            ->badge()
                            ->color(fn (?TrainingAccessStatus $state): string => match ($state) {
                                TrainingAccessStatus::Trial => 'info',
                                TrainingAccessStatus::Active, TrainingAccessStatus::Free => 'success',
                                TrainingAccessStatus::Expired => 'warning',
                                TrainingAccessStatus::Revoked => 'danger',
                                null => 'gray',
                            })
                            ->formatStateUsing(fn (?TrainingAccessStatus $state): string => match ($state) {
                                TrainingAccessStatus::Trial => 'Trial',
                                TrainingAccessStatus::Active => 'Active',
                                TrainingAccessStatus::Free => 'Free',
                                TrainingAccessStatus::Expired => 'Expirado',
                                TrainingAccessStatus::Revoked => 'Revocado',
                                null => 'Sin acceso',
                            }),
                        TextEntry::make('trainingAccess.status')
                            ->label('Estado (crudo, persistido)')
                            ->placeholder('—'),
                        TextEntry::make('trainingAccess.expires_at')->label('Vigente hasta')->dateTime()->placeholder('Sin vencimiento'),
                        TextEntry::make('trainingAccess.granted_at')->label('Otorgado el')->dateTime()->placeholder('—'),
                        TextEntry::make('trainingAccess.granted_by')->label('Origen')->placeholder('—'),
                        TextEntry::make('trainingAccess.payment_id')->label('Payment que lo originó')->placeholder('— (no originado por un Payment)'),
                    ]),

                Section::make('Historial de acceso (auditoría)')
                    ->description('Registro append-only de transiciones administrativas. Nunca autoritativo para el acceso técnico actual — solo lectura.')
                    ->schema([
                        RepeatableEntry::make('trainingAccess.audits')
                            ->label('')
                            ->schema([
                                TextEntry::make('action')
                                    ->label('Acción')
                                    ->badge()
                                    ->formatStateUsing(fn (TrainingAccessAuditAction $state): string => match ($state) {
                                        TrainingAccessAuditAction::TrialGranted => 'Trial otorgado',
                                        TrainingAccessAuditAction::FreeGranted => 'Free otorgado',
                                        TrainingAccessAuditAction::Extended => 'Extendido',
                                        TrainingAccessAuditAction::Revoked => 'Revocado',
                                        TrainingAccessAuditAction::Reactivated => 'Reactivado',
                                    }),
                                TextEntry::make('previous_status')->label('Estado anterior')->placeholder('—'),
                                TextEntry::make('new_status')->label('Estado nuevo'),
                                TextEntry::make('previous_expires_at')->label('Vencía')->dateTime()->placeholder('—'),
                                TextEntry::make('new_expires_at')->label('Vence ahora')->dateTime()->placeholder('—'),
                                TextEntry::make('performedBy.name')->label('Administrador'),
                                TextEntry::make('reason')->label('Motivo')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                            ])
                            ->columns(4)
                            ->placeholder('Sin cambios administrativos registrados todavía.'),
                    ]),

                // Solo lectura — el editor real de TrainingProfile es el
                // flujo conversacional de onboarding, no Cliente.
                Section::make('Entrenamiento')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('trainingProfile.goal')->label('Objetivo')->placeholder('—'),
                        TextEntry::make('trainingProfile.experience_level')->label('Nivel')->placeholder('—'),
                        TextEntry::make('trainingProfile.training_location')->label('Lugar de entrenamiento')->placeholder('—'),
                        TextEntry::make('trainingProfile.sessions_per_week')->label('Sesiones/semana')->placeholder('—'),
                        TextEntry::make('trainingProfile.safety_status')
                            ->label('Estado de seguridad')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'flagged_for_review' ? 'danger' : 'success')
                            ->placeholder('—'),
                    ]),

                // Solo lectura — el flujo real de revisión sigue siendo
                // DeclaredHealthConditionRecorder (Bloque 2), nunca
                // reimplementado aquí.
                Section::make('Salud y seguridad')
                    ->schema([
                        RepeatableEntry::make('declaredHealthConditions')
                            ->label('Condiciones declaradas')
                            ->schema([
                                TextEntry::make('original_text')->label('Declaración')->columnSpanFull(),
                                TextEntry::make('category')->label('Categoría'),
                                TextEntry::make('status')->label('Estado')->badge(),
                                TextEntry::make('declared_at')->label('Fecha')->dateTime(),
                            ])
                            ->columns(3)
                            ->placeholder('Sin condiciones declaradas.'),

                        RepeatableEntry::make('trainingRestrictions')
                            ->label('Restricciones vigentes')
                            ->schema([
                                TextEntry::make('body_region')->label('Zona'),
                                TextEntry::make('restriction_type')->label('Tipo'),
                                TextEntry::make('status')->label('Estado')->badge(),
                            ])
                            ->columns(3)
                            ->placeholder('Sin restricciones registradas.'),
                    ]),

                // Resumen — el detalle completo de cada Payment (comprobantes,
                // extracción IA, confirmar/rechazar) sigue viviendo
                // exclusivamente en PaymentResource; aquí no hay acciones.
                Section::make('Pagos')
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->label('')
                            ->schema([
                                TextEntry::make('id')->label('Pago #'),
                                TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge()
                                    ->color(fn (PaymentStatus $state): string => match ($state) {
                                        PaymentStatus::Pending => 'gray',
                                        PaymentStatus::UnderReview => 'warning',
                                        PaymentStatus::Confirmed => 'success',
                                        PaymentStatus::Rejected => 'danger',
                                        PaymentStatus::Expired => 'gray',
                                    }),
                                TextEntry::make('amount')->label('Monto')->money(fn ($record) => $record->currency ?? 'COP')->placeholder('—'),
                                TextEntry::make('membership_months')->label('Meses')->placeholder('—'),
                                TextEntry::make('created_at')->label('Solicitado')->since(),
                            ])
                            ->columns(5)
                            ->placeholder('Sin pagos registrados.'),
                    ]),

                Section::make('Actividad')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('workout_sessions_count')
                            ->label('Rutinas completadas')
                            ->state(fn (Contact $record) => $record->workoutSessions()->whereNotNull('completed_at')->count()),
                        TextEntry::make('last_activity')
                            ->label('Última actividad')
                            ->state(function (Contact $record): ?string {
                                $last = $record->workoutSessions()
                                    ->whereNotNull('completed_at')
                                    ->latest('completed_at')
                                    ->first();

                                return $last?->completed_at?->diffForHumans();
                            })
                            ->placeholder('Sin actividad'),
                    ]),
            ]);
    }
}
