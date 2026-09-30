<?php

namespace Webkul\Employee\Enums;

/**
 * Values the AttendanceRecord.source column may hold. AttendanceRecord is
 * deliberately NOT cast to this enum: pre-existing rows may carry other
 * strings and existing tests compare raw strings.
 */
enum AttendanceSource: string
{
    case Manual = 'manual';

    case Import = 'import';

    case Biometric = 'biometric';

    case Api = 'api';

    case Gps = 'gps';

    case SelfService = 'self_service';

    public function label(): string
    {
        return match ($this) {
            self::Manual      => 'Manual',
            self::Import      => 'Import',
            self::Biometric   => 'Biometric',
            self::Api         => 'API',
            self::Gps         => 'GPS check-in',
            self::SelfService => 'Self-service (remote)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Sources backed by a verification record; their times must never be edited silently. */
    public static function evidenceBacked(): array
    {
        return [self::Gps->value, self::SelfService->value];
    }
}
