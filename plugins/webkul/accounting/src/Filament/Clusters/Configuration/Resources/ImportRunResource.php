<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\Pages\ListImportRuns;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\Pages\ViewImportRun;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportRunResource\RelationManagers\SourceRowsRelationManager;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Support\AccountingPermissions;

class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'Import Runs';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('profile.name')->label('Profile')->searchable(),
                TextColumn::make('profile.entity_type')->label('Entity')->badge(),
                TextColumn::make('profile_version')->label('Version'),
                TextColumn::make('original_filename')->label('File')->limit(35),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('passed_rows')->label('Pass')->numeric()->color('success'),
                TextColumn::make('warning_rows')->label('Warnings')->numeric()->color('warning'),
                TextColumn::make('failed_rows')->label('Errors')->numeric()->color('danger'),
                TextColumn::make('duplicate_rows')->label('Duplicates')->numeric()->color('warning'),
                TextColumn::make('imported_rows')->label('Imported')->numeric(),
                TextColumn::make('importedBy.name')->label('User')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'previewed'             => 'Previewed', 'processing' => 'Processing', 'completed' => 'Completed',
                    'completed_with_review' => 'Completed with review', 'completed_with_rejections' => 'Completed with rejections',
                    'failed'                => 'Failed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label('Confirm import')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(AccountingPermissions::RunConfiguredImports)
                    ->visible(fn (ImportRun $record): bool => $record->status === 'previewed'
                        && ($record->failed_rows === 0 || $record->profile?->failure_policy !== 'reject_file'))
                    ->schema(fn (ImportRun $record): array => [
                        Toggle::make('discard_duplicates')
                            ->label("Discard {$record->duplicate_rows} detected duplicate row(s)")
                            ->helperText('Duplicates remain in the audit trail but are never written to canonical ERP records.')
                            ->required($record->duplicate_rows > 0)
                            ->visible($record->duplicate_rows > 0),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(fn (ImportRun $record): string => match ($record->profile?->entity_type) {
                        'opening_balance' => 'This creates one balanced, posted opening-balance journal from every passing non-zero row. Review the preview totals before confirming.',
                        'journal_entry'   => 'This creates balanced draft journals grouped by JE ID. They remain in review until separately approved and posted.',
                        'bank_statement'  => 'This creates a validated bank statement and reviewable transaction mappings. It does not post bank journals automatically.',
                        default           => 'This creates canonical ERP records for every passing row. Imported accounting documents remain draft unless this message explicitly states otherwise.',
                    })
                    ->action(function (ImportRun $record, array $data): void {
                        $completed = app(ImportExecutionService::class)->confirm(
                            $record,
                            Auth::id(),
                            (bool) ($data['discard_duplicates'] ?? false),
                        );
                        Notification::make()->success()->title("{$completed->imported_rows} rows imported")->send();
                    }),
                Action::make('downloadRejectedRows')
                    ->label('Download rejected rows')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(AccountingPermissions::RunConfiguredImports)
                    ->visible(fn (ImportRun $record): bool => $record->failed_rows > 0)
                    ->action(function (ImportRun $record) {
                        $csv = app(ImportExecutionService::class)->exportRejectedRows($record);

                        return response()->streamDownload(function () use ($csv): void {
                            echo $csv;
                        }, "{$record->reference}-rejected-rows.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [SourceRowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view'  => ViewImportRun::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::RunConfiguredImports) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
