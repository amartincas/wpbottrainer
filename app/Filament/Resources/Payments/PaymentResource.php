<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 8: sin páginas de crear/editar a propósito — un Payment nunca se
 * crea ni se edita a mano desde el panel, solo se REVISA (confirmar/
 * rechazar, ver PaymentsTable). Es un registro del sistema, no un dato
 * administrable libremente.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static ?string $navigationLabel = 'Pagos';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->whereHas('contact', fn (Builder $q) => $q->where('tenant_id', Auth::user()?->tenant_id));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
