<?php

namespace Webkul\Account\Filament\Resources\BillResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Filament\Resources\BillResource;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ListInvoices as BaseListBills;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Support\Models\Company;

class ListBills extends BaseListBills
{
    protected static string $resource = BillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle'),
            Action::make('syncDrive')
                ->label('Sync from Drive')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => config('accounting_drive.enabled', false))
                ->action(function () {
                    $companyId = Auth::user()?->default_company_id;
                    $company = $companyId ? Company::query()->find($companyId) : null;
                    if (! $company) {
                        Notification::make()->danger()->title('No default company set')->send();

                        return;
                    }

                    try {
                        $touched = app(DriveIngestionService::class)->syncInbound($company);
                        $count = count($touched);

                        Notification::make()
                            ->success()
                            ->title('Google Drive Sync Complete')
                            ->body("Retrieved {$count} document(s) across all company Drive folders. Open Drive Ingestion Review to confirm or reject them.")
                            ->actions([
                                Action::make('review')
                                    ->label('Review Documents')
                                    ->button()
                                    ->url(url('/admin/accounting/configuration/drive-ingestion-classifications')),
                            ])
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Drive Sync Failed')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
