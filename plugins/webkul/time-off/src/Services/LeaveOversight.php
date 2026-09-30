<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Services\HrHierarchyService;

/**
 * Who may see OTHER people's leave (the company overview calendar, the
 * leave-type chart, the Management screens), and whose leave they see.
 *
 * Seeing other people's leave requires the same permission as the
 * Management -> Time Off screen (LeavePolicy::viewAny), and is always
 * limited to the viewer's HR hierarchy: HR with hr_view_all_records sees
 * the whole company, a line manager sees their team, and an employee --
 * who only holds the "my time off" permissions -- sees nobody but
 * themselves (through My Time, not through these screens).
 */
class LeaveOversight
{
    public const PERMISSION = 'view_any_time_off_time::off';

    public static function canOversee(): bool
    {
        return (bool) Auth::user()?->can(self::PERMISSION);
    }

    /** @return Collection<int, int> */
    public static function visibleEmployeeIds(): Collection
    {
        $user = Auth::user();
        if (! $user || ! self::canOversee()) {
            return collect();
        }

        return app(HrHierarchyService::class)->visibleEmployeeIds($user, (int) $user->default_company_id);
    }
}
