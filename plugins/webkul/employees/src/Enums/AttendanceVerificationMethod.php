<?php

namespace Webkul\Employee\Enums;

/**
 * Planned future methods (do not add until implemented): qr, gps_qr,
 * biometric, native_app.
 */
enum AttendanceVerificationMethod: string
{
    case Gps = 'gps';

    case None = 'none';

    case Manual = 'manual';
}
