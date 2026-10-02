<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\RoleActionCenterService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class PendingActionsCenterWidget extends Widget
{
    protected string $view = 'filament.widgets.pending-actions-center';

    protected static bool $isLazy = false;

    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        if (! $user) {
            return ['data' => ['pending_count' => 0, 'items' => []]];
        }

        $companyId = (int) ($user->default_company_id ?? 1);

        return [
            'data' => app(RoleActionCenterService::class)->getPendingActionsFor($user, $companyId),
        ];
    }
}
