<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasLabel
{
    case Invoice = 'invoice';

    case Bill = 'bill';

    case Receipt = 'receipt';

    case BankStatement = 'bank_statement';

    case PaymentEvidence = 'payment_evidence';

    case Contract = 'contract';

    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Invoice         => __('accounting::enums/document-type.invoice'),
            self::Bill            => __('accounting::enums/document-type.bill'),
            self::Receipt         => __('accounting::enums/document-type.receipt'),
            self::BankStatement   => __('accounting::enums/document-type.bank-statement'),
            self::PaymentEvidence => __('accounting::enums/document-type.payment-evidence'),
            self::Contract        => __('accounting::enums/document-type.contract'),
            self::Other           => __('accounting::enums/document-type.other'),
        };
    }

    public static function options(): array
    {
        return [
            self::Invoice->value         => __('accounting::enums/document-type.invoice'),
            self::Bill->value            => __('accounting::enums/document-type.bill'),
            self::Receipt->value         => __('accounting::enums/document-type.receipt'),
            self::BankStatement->value   => __('accounting::enums/document-type.bank-statement'),
            self::PaymentEvidence->value => __('accounting::enums/document-type.payment-evidence'),
            self::Contract->value        => __('accounting::enums/document-type.contract'),
            self::Other->value           => __('accounting::enums/document-type.other'),
        ];
    }
}
