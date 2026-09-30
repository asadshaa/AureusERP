<?php

namespace Webkul\TimeOff\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\TimeOff\Filament\Widgets\OverviewCalendarWidget;
use Webkul\TimeOff\Services\LeaveOversight;

class Overview extends BaseDashboard
{
    use HasPageShield {
        canAccess as shieldCanAccess;
    }

    protected static string $routePath = 'time-off';

    protected static ?int $navigationSort = 2;

    /** The company leave calendar shows other people's leave: HR / managers only. */
    public static function canAccess(): bool
    {
        return static::shieldCanAccess() && LeaveOversight::canOversee();
    }

    protected static function getPagePermission(): ?string
    {
        return 'page_time_off_overview';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return null;
    }

    public function getTitle(): string
    {
        return __('time-off::filament/pages/overview.navigation.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('time-off::filament/pages/overview.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::TimeOff;
    }

    public function getWidgets(): array
    {
        return [
            OverviewCalendarWidget::class,
        ];
    }
}
