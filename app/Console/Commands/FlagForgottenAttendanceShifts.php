<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;

class FlagForgottenAttendanceShifts extends Command
{
    protected $signature = 'hr:flag-forgotten-shifts';

    protected $description = 'Send GPS / self-service attendance shifts that were never checked out (older than max_shift_hours) to the HR review queue and notify the line manager';

    public function handle(GeofencedAttendanceService $attendance): int
    {
        $flagged = $attendance->flagForgottenShifts();

        $this->info("Flagged {$flagged} forgotten shift(s) for review.");

        return self::SUCCESS;
    }
}
