<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Pages\ManageChats;
use App\Filament\Resources\Tenants\Schemas\TenantWizardForm;
use App\Filament\Resources\Tenants\TenantResource;
use App\Services\WhatsAppService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    public function form(Schema $schema): Schema
    {
        return TenantWizardForm::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-m-signal')
                ->tooltip('Test WhatsApp API Connection')
                ->hidden(fn () => !$this->record->wa_phone_number_id || !$this->record->wa_access_token)
                ->action(function () {
                    $result = WhatsAppService::testConnection(
                        $this->record->wa_phone_number_id,
                        $this->record->wa_access_token
                    );

                    if ($result['success']) {
                        // Show success notification with confetti
                        Notification::make()
                            ->title('¡Conexión Exitosa!')
                            ->body($result['message'])
                            ->success()
                            ->icon('heroicon-o-check-circle')
                            ->send();

                        // Dispatch confetti animation
                        $this->dispatch('trigger-confetti');

                        session()->put('tenant_connection_tested', true);
                        session()->put('tenant_connection_tested_at', now());
                    } else {
                        Notification::make()
                            ->title('✗ Connection Failed')
                            ->body($result['message'])
                            ->danger()
                            ->icon('heroicon-o-x-circle')
                            ->send();

                        session()->forget('tenant_connection_tested');
                    }
                }),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Trigger welcome message after tenant is saved
        WhatsAppService::sendWelcomeMessage($this->record);

        // Show completion notification
        Notification::make()
            ->title('Configuración guardada')
            ->body('Los cambios en tu tenant han sido guardados exitosamente.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        // Redirect to WhatsApp Chat Center after successful save
        return ManageChats::getUrl();
    }
}
