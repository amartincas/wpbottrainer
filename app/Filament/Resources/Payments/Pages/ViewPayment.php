<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Confirmar/Rechazar viven en la tabla (PaymentsTable) para
            // estar disponibles también desde la lista, sin duplicar la
            // lógica en dos lugares.
        ];
    }
}
