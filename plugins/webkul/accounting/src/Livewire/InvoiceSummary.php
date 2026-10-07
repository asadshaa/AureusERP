<?php

namespace Webkul\Accounting\Livewire;

use Webkul\Account\Enums\MoveType;
use Webkul\Account\Livewire\InvoiceSummary as BaseInvoiceSummary;
use Webkul\Account\Models\Payment;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\CreditNoteResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\PaymentResource as CustomerPaymentResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\PaymentResource as VendorPaymentResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\RefundResource;

class InvoiceSummary extends BaseInvoiceSummary
{
    public function getResourceUrl($record): ?string
    {
        $moveType = $record['move_type'] instanceof MoveType
            ? $record['move_type']
            : (is_string($record['move_type'] ?? null) ? MoveType::tryFrom($record['move_type']) : null);

        if (! $moveType) {
            return null;
        }

        $payment = ! empty($record['account_payment_id'])
            ? Payment::find($record['account_payment_id'])
            : null;

        return match ($moveType) {
            MoveType::OUT_INVOICE => ! empty($record['move_id']) ? InvoiceResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::IN_INVOICE  => ! empty($record['move_id']) ? BillResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::OUT_REFUND  => ! empty($record['move_id']) ? CreditNoteResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::IN_REFUND   => ! empty($record['move_id']) ? RefundResource::getUrl('view', ['record' => $record['move_id']]) : null,
            MoveType::ENTRY       => match ($payment?->partner_type) {
                'customer', 'company' => CustomerPaymentResource::getUrl('view', ['record' => $record['account_payment_id']]),
                'supplier'            => VendorPaymentResource::getUrl('view', ['record' => $record['account_payment_id']]),
                default               => null,
            },
            default               => null,
        };
    }
}
