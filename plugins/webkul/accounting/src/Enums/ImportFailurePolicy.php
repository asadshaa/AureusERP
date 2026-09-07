<?php

namespace Webkul\Accounting\Enums;

enum ImportFailurePolicy: string
{
    case RejectFile = 'reject_file';
    case RejectFailedRows = 'reject_rows';
    case NeedsReview = 'flag_review';
    case WarnContinue = 'warn_continue';

    public static function fromValue(string|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return match ($value) {
            'reject_rows', 'reject_failed_rows' => self::RejectFailedRows,
            'flag_review', 'needs_review'       => self::NeedsReview,
            'warn_continue'                     => self::WarnContinue,
            default                             => self::RejectFile,
        };
    }

    public static function options(): array
    {
        return [
            self::RejectFile->value       => 'Reject the entire file when any row fails',
            self::RejectFailedRows->value => 'Reject failed rows and import valid rows',
            self::NeedsReview->value      => 'Flag failed rows for review and import valid rows',
            self::WarnContinue->value     => 'Continue past configured non-critical warnings',
        ];
    }
}
