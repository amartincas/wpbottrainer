<?php

namespace App\Filament\Resources;

use App\CustomerCare\Models\CustomerServiceRequest;
use App\Filament\Resources\CustomerServiceRequest\Pages\ListCustomerServiceRequests;
use App\Filament\Resources\CustomerServiceRequest\Pages\ViewCustomerServiceRequest;
use App\Filament\Resources\CustomerServiceRequest\Schemas\CustomerServiceRequestInfolist;
use App\Filament\Resources\CustomerServiceRequest\Tables\CustomerServiceRequestsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 14 — deliberadamente de solo lectura (sin create/edit/delete): es
 * un registro histórico append-only de un hecho ya ocurrido, nunca algo
 * administrable a mano — mismo criterio que `PaymentResource`.
 *
 * Justificación de por qué SÍ se crea este Resource (contra el default
 * "no crear" del diseño): (1) no existe ningún Resource de `AlertLog` en
 * el panel — no hay forma alternativa de navegar este historial; (2)
 * `WhatsAppAdminAlertChannel` tiene throttle propio (5 min) — una alerta
 * duplicada real puede no entregarse, mientras que `CustomerServiceRequest`
 * (nunca throttled, se crea siempre) es la única fuente completa y
 * confiable. Ver docs/DECISIONS.md.
 */
class CustomerServiceRequestResource extends Resource
{
    protected static ?string $model = CustomerServiceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Lifebuoy;

    protected static ?string $navigationLabel = 'Solicitudes de atención';

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
        return CustomerServiceRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerServiceRequestInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerServiceRequests::route('/'),
            'view' => ViewCustomerServiceRequest::route('/{record}'),
        ];
    }
}
