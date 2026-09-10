<?php

namespace Webkul\Invoice;

use Filament\Panel;
use Livewire\Livewire;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Invoice\Livewire\InvoiceSummary;
use Webkul\Invoice\Models\Invoice;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class InvoiceServiceProvider extends PackageServiceProvider
{
    public static string $name = 'invoices';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasTranslations()
            ->hasDependencies([
                'accounts',
                'accounting',
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('invoices');
    }

    public function packageBooted(): void
    {
        Livewire::component('invoice-invoice-summary', InvoiceSummary::class);

        // See AccountingServiceProvider::registerDocumentAttachmentRelations()
        // for why this is registered here rather than on the Move/Invoice
        // model files themselves: invoices depends on accounting, never
        // the other way, so this is the one safe direction to wire it.
        Invoice::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(InvoicePlugin::make());
        });
    }
}
