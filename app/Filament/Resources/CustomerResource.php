<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Customer\Pages\EditCustomer;
use App\Filament\Resources\Customer\Pages\ListCustomers;
use App\Filament\Resources\Customer\Pages\ViewCustomer;
use App\Filament\Resources\Customer\Schemas\CustomerForm;
use App\Filament\Resources\Customer\Schemas\CustomerInfolist;
use App\Filament\Resources\Customer\Tables\CustomersTable;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 12 — "Clientes": vista consolidada de `Contact` + su membresía/
 * acceso (`TrainingAccess`), NO un CRUD genérico. Sin página `create` a
 * propósito: un Contact siempre se origina desde el flujo conversacional
 * de WhatsApp (Ingest/webhook), nunca se crea a mano desde el panel — igual
 * criterio que `PaymentResource` (sin create/edit) pero aquí sí existe un
 * `EditCustomer` deliberadamente acotado (solo identidad, ver
 * `CustomerForm`). Las transiciones de membresía/acceso NO son un form:
 * viven como 5 acciones administrativas explícitas en
 * `ViewCustomer::getHeaderActions()`, cada una delegando en
 * `App\Training\Support\TrainingAccessAdministrationService`.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?int $navigationSort = 0;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->where('tenant_id', Auth::user()?->tenant_id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
