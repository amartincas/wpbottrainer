<?php

namespace App\Filament\Widgets;

use App\Models\Contact;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class UnprocessedContactsStats extends BaseWidget
{
    protected function getStats(): array
    {
        $tenantId = Auth::user()->tenant_id;

        $unprocessedCount = Contact::where('tenant_id', $tenantId)
            ->where('is_processed', false)
            ->count();

        $totalCount = Contact::where('tenant_id', $tenantId)->count();
        $processedCount = $totalCount - $unprocessedCount;

        return [
            Stat::make('Unprocessed Contacts', $unprocessedCount)
                ->description('Contacts awaiting follow-up')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->url('/admin/contacts?tableFilters%5Bis_processed%5D%5Bvalue%5D=false'),

            Stat::make('Processed Contacts', $processedCount)
                ->description('Successfully handled contacts')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Total Contacts', $totalCount)
                ->description('All contacts combined')
                ->descriptionIcon('heroicon-m-document')
                ->color('info'),
        ];
    }
}
