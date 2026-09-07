<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ManagePerformanceReviews;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class PerformanceReviewResource extends Resource
{
    protected static ?string $model = PerformanceReview::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Performance Reviews';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cycle_id')->relationship('cycle', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->disabled()->dehydrated(),
            Select::make('employee_id')->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->disabled()->dehydrated(),
            Select::make('reviewer_id')->relationship('reviewer', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->searchable()->preload(),
            TextInput::make('self_rating')->numeric()->minValue(0)->maxValue(5),
            TextInput::make('manager_rating')->numeric()->minValue(0)->maxValue(5),
            Select::make('status')->options([
                'self_review' => 'Self review', 'manager_review' => 'Manager review', 'completed' => 'Completed',
            ])->required(),
            KeyValue::make('competency_ratings')->columnSpanFull(),
            Textarea::make('self_comments')->columnSpanFull(),
            Textarea::make('manager_comments')->columnSpanFull(),
            Textarea::make('improvement_plan')->columnSpanFull(),
            Textarea::make('promotion_recommendation')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('cycle.name')->searchable()->sortable(),
            TextColumn::make('employee.name')->searchable()->sortable(),
            TextColumn::make('reviewer.name')->label('Manager reviewer')->placeholder('—'),
            TextColumn::make('self_rating')->numeric(decimalPlaces: 2)->placeholder('—'),
            TextColumn::make('manager_rating')->numeric(decimalPlaces: 2)->placeholder('—'),
            TextColumn::make('status')->badge(),
            TextColumn::make('completed_at')->dateTime()->placeholder('Pending'),
        ])->filters([
            SelectFilter::make('status')->options([
                'self_review' => 'Self review', 'manager_review' => 'Manager review', 'completed' => 'Completed',
            ]),
        ])->recordActions([EditAction::make()]);
    }

    /**
     * Company scoping alone let any user holding hr_manage_performance see
     * every employee's ratings and review comments. Scope to the
     * requesting user's HR hierarchy, matching every other personal-data
     * list in this module. Note: PerformanceCycle itself (the
     * cycle/campaign record — name, dates, status) carries no employee_id
     * and is intentionally left company-scoped only; it is administrative
     * configuration, not an individual's data — PerformanceCycleResource
     * is unchanged.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;

        return parent::getEloquentQuery()
            ->where('company_id', $companyId)
            ->whereIn(
                'employee_id',
                $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : [],
            );
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(HrPermissions::ManagePerformance) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceReviews::route('/')];
    }
}
