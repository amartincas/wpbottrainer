<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\UnprocessedContactsStats;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        return [
            UnprocessedContactsStats::class,
        ];
    }
}
