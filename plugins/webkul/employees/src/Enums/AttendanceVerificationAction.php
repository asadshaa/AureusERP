<?php

namespace Webkul\Employee\Enums;

enum AttendanceVerificationAction: string
{
    case CheckIn = 'check_in';

    case CheckOut = 'check_out';

    case HrCorrection = 'hr_correction';

    case Review = 'review';

    /** An evidence-backed attendance record was deleted (snapshot + reason kept here). */
    case RecordDeleted = 'record_deleted';

    /** Raised by the system, e.g. a shift left open past max_shift_hours. */
    case SystemFlag = 'system_flag';
}
