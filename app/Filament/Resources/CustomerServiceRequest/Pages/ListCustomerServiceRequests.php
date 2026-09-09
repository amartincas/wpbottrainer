<?php

namespace App\Filament\Resources\CustomerServiceRequest\Pages;

use App\Filament\Resources\CustomerServiceRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerServiceRequests extends ListRecords
{
    protected static string $resource = CustomerServiceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
