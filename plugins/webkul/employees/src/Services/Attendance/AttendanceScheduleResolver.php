<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Employee\Models\Employee;

/**
 * Timezone, attendance-date and scheduled-window resolution. Reuses the
 * existing Employee.time_zone -> Calendar.timezone -> app timezone chain
 * (Company has no timezone column) and Calendar::getAttendanceIntervalsBatch()
 * for the day's working window; nothing is invented here.
 */
class AttendanceScheduleResolver
{
    public function timezoneFor(Employee $employee): string
    {
        foreach ([$employee->time_zone, $employee->calendar?->timezone, config('app.timezone')] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    /** The employee-local calendar date of $nowUtc (the date of a check-in owns the whole shift). */
    public function attendanceDateFor(Employee $employee, CarbonImmutable $nowUtc): string
    {
        return $nowUtc->setTimezone($this->timezoneFor($employee))->toDateString();
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} scheduled start/end in UTC, or nulls
     *                                                         (no calendar, day off, or any calendar failure)
     */
    public function scheduledWindowFor(Employee $employee, string $attendanceDate): array
    {
        $calendar = $employee->calendar;
        if (! $calendar) {
            return [null, null];
        }

        try {
            $timezone = $this->timezoneFor($employee);

            // Calendar::getAttendanceIntervalsBatch() (type-hinted to mutable
            // Carbon) lays a calendar's hour_from/hour_to out as UTC wall-clock
            // times, whatever timezone is passed. So ask it for the day in UTC
            // and reinterpret those wall-clock values in the employee's own
            // timezone: "09:00-17:00" then means 09:00-17:00 local. This still
            // reuses the calendar's weekday, date-range and two-week logic.
            $dayStart = Carbon::parse($attendanceDate.' 00:00:00', 'UTC');
            $dayEnd = $dayStart->copy()->endOfDay();

            $intervals = collect($calendar->getAttendanceIntervalsBatch($dayStart, $dayEnd, timezone: 'UTC')[null] ?? []);
            if ($intervals->isEmpty()) {
                return [null, null];
            }

            $toLocal = fn ($moment): CarbonImmutable => CarbonImmutable::parse($moment->format('Y-m-d H:i:s'), $timezone)->utc();

            return [
                $intervals->map(fn (array $interval) => $toLocal($interval[0]))->min(),
                $intervals->map(fn (array $interval) => $toLocal($interval[1]))->max(),
            ];
        } catch (Throwable $e) {
            // A schedule failure must never block attendance; it only means
            // late/early minutes are not computed (same as no calendar).
            Log::warning('Attendance schedule resolution failed: '.$e->getMessage(), ['employee_id' => $employee->id]);

            return [null, null];
        }
    }
}
