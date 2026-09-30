<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\WorkLocationResource\Pages\ListWorkLocations;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Support\HrPermissions;

class WorkLocationResource extends Resource
{
    protected static ?string $model = WorkLocation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $cluster = Configurations::class;

    public static function getModelLabel(): string
    {
        return __('employees::filament/clusters/configurations/resources/work-location.title');
    }

    public static function getNavigationGroup(): string
    {
        return __('employees::filament/clusters/configurations/resources/work-location.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('employees::filament/clusters/configurations/resources/work-location.navigation.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.form.name'))
                    ->required()
                    ->maxLength(255),
                ToggleButtons::make('location_type')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.form.location-type'))
                    ->inline()
                    ->options(WorkLocationEnum::class)
                    ->required(),
                TextInput::make('location_number')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.form.location-number')),
                // Was an unscoped list of every company. Offer only companies the
                // user may act in; WorkLocation::saving() re-checks this server-side.
                Select::make('company_id')
                    ->searchable()
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.form.company'))
                    ->required()
                    ->preload()
                    ->default(fn (): ?int => Auth::user()?->default_company_id)
                    ->relationship('company', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn('id', self::accessibleCompanyIds())),
                Toggle::make('is_active')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.form.status'))
                    ->required(),
                Section::make('Mobile check-in geofence')
                    ->description('Employees assigned to this workplace can check in from their phone only while inside this circle. Enter the coordinates of the workplace centre (for example, right-click the spot on any map and copy the coordinates). Browser location can be spoofed on a tampered phone, so flagged check-ins are reviewed by HR rather than blindly trusted.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Get $get): bool => ! in_array($get('location_type'), [WorkLocationEnum::Home, WorkLocationEnum::Home->value], true))
                    ->disabled(fn (): bool => ! Auth::user()?->can(HrPermissions::ManageAttendanceGeofences))
                    ->schema([
                        Toggle::make('geofence_enabled')
                            ->label('Enable mobile check-in for this workplace')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->step(0.0000001)
                            ->required(fn (Get $get): bool => (bool) $get('geofence_enabled')),
                        TextInput::make('longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->step(0.0000001)
                            ->required(fn (Get $get): bool => (bool) $get('geofence_enabled')),
                        TextInput::make('geofence_radius_meters')
                            ->label('Allowed radius')
                            ->integer()
                            ->suffix('metres')
                            ->minValue((int) config('hr_attendance_geofence.radius_min_meters'))
                            ->maxValue((int) config('hr_attendance_geofence.radius_max_meters'))
                            ->default((int) config('hr_attendance_geofence.default_radius_meters'))
                            ->required(fn (Get $get): bool => (bool) $get('geofence_enabled'))
                            ->helperText('Between '.config('hr_attendance_geofence.radius_min_meters').' and '.config('hr_attendance_geofence.radius_max_meters').' metres. Around 100-200 m suits a typical office; GPS is less precise indoors.'),
                        View::make('employees::filament.components.use-my-location')->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<int, int> */
    public static function accessibleCompanyIds(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        return $user->allowedCompanies()->pluck('companies.id')
            ->push((int) $user->default_company_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Work locations now hold GPS coordinates, so the list must be scoped to the
     * user's default company or allowed companies.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('company_id', self::accessibleCompanyIds());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderableColumns()
            ->columnManagerColumns(2)
            ->columns([
                TextColumn::make('id')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.id'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.name'))
                    ->searchable(),
                TextColumn::make('location_type')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.location-type'))
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.status'))
                    ->boolean(),
                // Coordinates are deliberately NOT a column: the list shows only whether a
                // geofence is on and how large it is.
                IconColumn::make('geofence_enabled')
                    ->label('Mobile check-in')
                    ->boolean(),
                TextColumn::make('geofence_radius_meters')
                    ->label('Radius')
                    ->suffix(' m')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('company.name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.company'))
                    ->sortable(),
                TextColumn::make('location_number')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.location-number'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.created-by'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.created-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.updated-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.columns.deleted-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.name'))
                    ->collapsible(),
                Group::make('creator.name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.created-by'))
                    ->collapsible(),
                Group::make('location_type')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.location-type'))
                    ->collapsible(),
                Group::make('company.name')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.company'))
                    ->collapsible(),
                Group::make('is_active')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.status'))
                    ->collapsible(),
                Group::make('created_at')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.created-at'))
                    ->collapsible(),
                Group::make('updated_at')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.groups.updated-at'))
                    ->date()
                    ->collapsible(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.status')),
                QueryBuilder::make()
                    ->constraintPickerColumns(2)
                    ->constraints([
                        TextConstraint::make('name')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.name'))
                            ->icon('heroicon-o-user'),
                        TextConstraint::make('location_type')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.location-type'))
                            ->icon('heroicon-o-map'),
                        TextConstraint::make('location_number')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.location-number'))
                            ->icon('heroicon-o-map'),
                        RelationshipConstraint::make('company')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.company'))
                            ->icon('heroicon-o-building-office')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                        RelationshipConstraint::make('creator')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.created-by'))
                            ->icon('heroicon-o-user')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                        DateConstraint::make('created_at')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.created-at')),
                        DateConstraint::make('updated_at')
                            ->label(__('employees::filament/clusters/configurations/resources/work-location.table.filters.updated-at')),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('employees::filament/clusters/configurations/resources/work-location.table.actions.edit.notification.title'))
                            ->body(__('employees::filament/clusters/configurations/resources/work-location.table.actions.edit.notification.body')),
                    ),
                DeleteAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('employees::filament/clusters/configurations/resources/work-location.table.actions.delete.notification.title'))
                            ->body(__('employees::filament/clusters/configurations/resources/work-location.table.actions.delete.notification.body')),
                    ),
                RestoreAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('employees::filament/clusters/configurations/resources/work-location.table.actions.restore.notification.title'))
                            ->body(__('employees::filament/clusters/configurations/resources/work-location.table.actions.restore.notification.body')),
                    ),
                ForceDeleteAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('employees::filament/clusters/configurations/resources/work-location.table.actions.force-delete.notification.title'))
                            ->body(__('employees::filament/clusters/configurations/resources/work-location.table.actions.force-delete.notification.body')),
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('employees::filament/clusters/configurations/resources/work-location.table.bulk-actions.delete.notification.title'))
                                ->body(__('employees::filament/clusters/configurations/resources/work-location.table.bulk-actions.delete.notification.body')),
                        ),
                    ForceDeleteBulkAction::make()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('employees::filament/clusters/configurations/resources/work-location.table.bulk-actions.force-delete.notification.title'))
                                ->body(__('employees::filament/clusters/configurations/resources/work-location.table.bulk-actions.force-delete.notification.body')),
                        ),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make()
                    ->icon('heroicon-o-plus-circle')
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('employees::filament/clusters/configurations/resources/work-location.table.actions.empty-state.notification.title'))
                            ->body(__('employees::filament/clusters/configurations/resources/work-location.table.actions.empty-state.notification.body')),
                    ),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->icon('heroicon-o-map')
                    ->placeholder('—')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.infolist.name')),
                TextEntry::make('location_type')
                    ->icon('heroicon-o-map')
                    ->placeholder('—')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.infolist.location-type')),
                TextEntry::make('location_number')
                    ->placeholder('—')
                    ->icon('heroicon-o-map')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.infolist.location-number')),
                TextEntry::make('company.name')
                    ->placeholder('—')
                    ->icon('heroicon-o-building-office')
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.infolist.company')),
                IconEntry::make('is_active')
                    ->boolean()
                    ->label(__('employees::filament/clusters/configurations/resources/work-location.infolist.status')),
                IconEntry::make('geofence_enabled')
                    ->boolean()
                    ->label('Mobile check-in'),
                TextEntry::make('latitude')
                    ->placeholder('—')
                    ->suffixAction(
                        Action::make('open_in_osm')
                            ->label('OpenStreetMap')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->url(fn (?WorkLocation $record): ?string => ($record?->latitude && $record?->longitude)
                                ? "https://www.openstreetmap.org/?mlat={$record->latitude}&mlon={$record->longitude}#map=18/{$record->latitude}/{$record->longitude}"
                                : null
                            )
                            ->openUrlInNewTab()
                            ->visible(fn (?WorkLocation $record): bool => $record?->latitude !== null && $record?->longitude !== null)
                    )
                    ->visible(fn (): bool => self::canSeeCoordinates()),
                TextEntry::make('longitude')
                    ->placeholder('—')
                    ->visible(fn (): bool => self::canSeeCoordinates()),
                TextEntry::make('geofence_radius_meters')
                    ->label('Allowed radius')
                    ->suffix(' m')
                    ->placeholder('—')
                    ->visible(fn (): bool => self::canSeeCoordinates()),
                TextEntry::make('openstreetmap_link')
                    ->label('Map')
                    ->state(fn (?WorkLocation $record): string => ($record?->latitude && $record?->longitude) ? 'View on OpenStreetMap' : '—')
                    ->url(fn (?WorkLocation $record): ?string => ($record?->latitude && $record?->longitude)
                        ? "https://www.openstreetmap.org/?mlat={$record->latitude}&mlon={$record->longitude}#map=18/{$record->latitude}/{$record->longitude}"
                        : null
                    )
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (?WorkLocation $record): bool => self::canSeeCoordinates() && $record?->latitude !== null && $record?->longitude !== null),
            ]);
    }

    private static function canSeeCoordinates(): bool
    {
        $user = Auth::user();

        return $user !== null
            && ($user->can(HrPermissions::ManageAttendanceGeofences) || $user->can(HrPermissions::ViewAttendanceLocationEvidence));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkLocations::route('/'),
        ];
    }
}
