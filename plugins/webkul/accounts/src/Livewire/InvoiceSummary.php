<?php

namespace Webkul\Account\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Filament\Resources\BillResource;
use Webkul\Account\Filament\Resources\CreditNoteResource;
use Webkul\Account\Filament\Resources\InvoiceResource;
use Webkul\Account\Filament\Resources\PaymentResource;
use Webkul\Account\Filament\Resources\RefundResource;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;

class InvoiceSummary extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public $record = null;

    public $subtotal = 0;

    public $totalDiscount = 0;

    public $totalTax = 0;

    public $grandTotal = 0;

    public $amountTax = 0;

    public $rounding = 0;

    public $currency = null;

    public $reconcilablePayments = null;

    public $reconciledPayments = null;

    protected $listeners = [
        'itemUpdated'           => 'refreshSummary',
        'refreshInvoiceSummary' => 'refreshFromRecord',
    ];

    public function refreshSummary($totals)
    {
        $this->subtotal = $totals['subtotal'];
        $this->totalTax = $totals['totalTax'];
        $this->grandTotal = $totals['grandTotal'];
        $this->amountTax = $totals['totalTax'];
        $this->rounding = $totals['rounding'];
    }

    public function refreshFromRecord()
    {
        $this->record?->refresh();
    }

    public function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label(__('accounts::filament/resources/invoice.summary.actions.reconcile.label'))
            ->icon('heroicon-o-check-circle')
            ->size('xs')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                $lines = MoveLine::where('id', $arguments['lineId'])->get();

                $lines = $lines->merge($this->record->lines->filter(fn ($line) => $line->account_id == $lines->first()->account_id && ! $line->reconciled
                ));

                AccountFacade::reconcile($lines);
            })
            ->after(fn () => $this->js('window.location.reload()'));
    }

    public function unReconcileAction(): Action
    {
        return Action::make('unReconcile')
            ->label(__('accounts::filament/resources/invoice.summary.actions.unreconcile.label'))
            ->icon('heroicon-o-x-circle')
            ->size('xs')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                $partialReconcile = PartialReconcile::find($arguments['partial_id']);

                AccountFacade::unReconcile($partialReconcile);
            })
            ->after(fn () => $this->js('window.location.reload()'));
    }

    public function getResourceUrl($record): ?string
    {
        $moveType = $record['move_type'] instanceof MoveType
            ? $record['move_type']
            : (is_string($record['move_type'] ?? null) ? MoveType::tryFrom($record['move_type']) : null);

        if (! $moveType) {
            return null;
        }

        return match ($moveType) {
            MoveType::OUT_INVOICE => ! empty($record['move_id']) ? InvoiceResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::IN_INVOICE  => ! empty($record['move_id']) ? BillResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::OUT_REFUND  => ! empty($record['move_id']) ? CreditNoteResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::IN_REFUND   => ! empty($record['move_id']) ? RefundResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::ENTRY       => ! empty($record['account_payment_id']) ? PaymentResource::getUrl('view', ['record' => $record['account_payment_id']]) : null,
            default               => null,
        };
    }

    public function getDriveSyncInfo(): ?array
    {
        if (! $this->record) {
            return null;
        }

        try {
            $attachment = $this->record->documentAttachments()
                ->with(['document.driveSync', 'document.creator'])
                ->latest()
                ->first();

            $sync = $attachment?->document?->driveSync;

            if ($sync && $sync->drive_file_id) {
                return [
                    'status'        => $sync->status?->value ?? (string) $sync->status,
                    'drive_file_id' => $sync->drive_file_id,
                    'url'           => "https://drive.google.com/file/d/{$sync->drive_file_id}/view",
                    'folder_path'   => $sync->last_sync_path ?: 'Google Drive',
                    'synced_at'     => $sync->synced_at?->format('M d, Y h:i A') ?? $sync->updated_at?->format('M d, Y h:i A'),
                    'uploader'      => $attachment->document?->creator?->name ?? 'Aureus System',
                    'file_name'     => $attachment->document?->title ?? 'Invoice Document',
                ];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function render()
    {
        $this->reconcilablePayments = $this->record?->getReconcilablePayments();

        $this->reconciledPayments = $this->record?->getReconciledPayments();

        return view('accounts::livewire/invoice-summary');
    }
}
