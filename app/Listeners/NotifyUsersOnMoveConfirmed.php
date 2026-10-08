<?php

namespace App\Listeners;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\CreditNoteResource;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\RefundResource;
use Webkul\Security\Models\User;

class NotifyUsersOnMoveConfirmed
{
    public function handle(MoveConfirmed $event): void
    {
        $move = $event->move;

        if (! $move) {
            return;
        }

        $poster = Auth::user() ?? $move->postedBy ?? $move->creator;
        $posterId = $poster?->id;

        // Notify other users of the same company
        $recipients = User::query()
            ->when($posterId, fn ($query) => $query->where('id', '!=', $posterId))
            ->where(function ($query) use ($move) {
                $query->whereHas('allowedCompanies', fn ($c) => $c->where('companies.id', $move->company_id))
                    ->orWhere('default_company_id', $move->company_id);
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $url = null;
        try {
            $url = match ($move->move_type) {
                MoveType::OUT_INVOICE => class_exists(InvoiceResource::class) ? InvoiceResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::IN_INVOICE  => class_exists(BillResource::class) ? BillResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::OUT_REFUND  => class_exists(CreditNoteResource::class) ? CreditNoteResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::IN_REFUND   => class_exists(RefundResource::class) ? RefundResource::getUrl('view', ['record' => $move->id]) : null,
                default               => null,
            };
        } catch (\Throwable) {
            $url = null;
        }

        $typeName = match ($move->move_type) {
            MoveType::OUT_INVOICE => 'Customer Invoice',
            MoveType::IN_INVOICE  => 'Vendor Bill',
            MoveType::OUT_REFUND  => 'Credit Note',
            MoveType::IN_REFUND   => 'Refund',
            default               => 'Journal Entry',
        };

        $posterName = $poster?->name ?? 'A team member';
        $formattedAmount = money($move->amount_total ?? 0, $move->currency?->name);

        $notification = Notification::make()
            ->title("{$typeName} Posted")
            ->body("{$posterName} just posted {$move->name} ({$formattedAmount})")
            ->icon('heroicon-o-check-circle')
            ->iconColor('success');

        if ($url) {
            $notification->actions([
                Action::make('view')
                    ->label("View {$typeName}")
                    ->button()
                    ->url($url),
            ]);
        }

        $notification->sendToDatabase($recipients);
    }
}
