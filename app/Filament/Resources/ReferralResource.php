<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Referral\Pages\ListReferrals;
use App\Filament\Resources\Referral\Pages\ViewReferral;
use App\Filament\Resources\Referral\Schemas\ReferralInfolist;
use App\Filament\Resources\Referral\Tables\ReferralsTable;
use App\Referrals\Models\Referral;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 13 — auditoría de atribución/recompensa de Referidos, un único
 * Resource (la recompensa, 1:1, se muestra inline en el Infolist — mismo
 * criterio que `PaymentInfolist` muestra el `TrainingAccess` resultante
 * sin necesitar un Resource separado). Sin páginas create/edit: es un
 * registro del sistema, nunca administrable a mano.
 */
class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserPlus;

    protected static ?string $navigationLabel = 'Referidos';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->whereHas('referredContact', fn (Builder $q) => $q->where('tenant_id', Auth::user()?->tenant_id));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return ReferralsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReferralInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferrals::route('/'),
            'view' => ViewReferral::route('/{record}'),
        ];
    }
}
