<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Support\Traits\HasRecordNavigationTabs;

class ViewCandidate extends ViewRecord
{
    use HasRecordNavigationTabs;

    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gotoEmployee')
                ->tooltip(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.goto-employee-tooltip'))
                ->visible(fn ($record) => $record->employee_id)
                ->icon('heroicon-s-arrow-top-right-on-square')
                ->iconButton()
                ->action(function (Candidate $record) {
                    $employee = $record->createEmployee();

                    return redirect(EmployeeResource::getUrl('view', ['record' => $employee]));
                }),
            Action::make('createEmployee')
                ->label(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.create-employee'))
                ->hidden(fn ($record) => $record->employee_id)
                ->action(function (Candidate $record) {
                    // createEmployee() throws when the candidate has more than
                    // one application and it's not unambiguous which one is
                    // being hired (see Candidate::createEmployee()) — surface
                    // that as a clear notification instead of an unhandled
                    // 500, same as the "no application at all" case below.
                    try {
                        $employee = $record->createEmployee();
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot convert this candidate yet')
                            ->body($exception->getMessage())
                            ->send();

                        return null;
                    }

                    // createEmployee() returns null when this candidate has no
                    // Applicant at all — there's nothing to convert. Passing
                    // null straight into getUrl() used to throw a raw
                    // UrlGenerationException instead of telling the recruiter
                    // what actually went wrong.
                    if (! $employee) {
                        Notification::make()
                            ->danger()
                            ->title('Nothing to convert')
                            ->body('This candidate has no job application on file yet — create one before converting to an employee.')
                            ->send();

                        return null;
                    }

                    return redirect(EmployeeResource::getUrl('edit', ['record' => $employee]));
                }),
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.header-actions.delete.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.header-actions.delete.notification.body'))
                ),
        ];
    }
}
