<?php

namespace Webkul\Accounting\Filament\Actions;

use Filament\Actions\Action;
use Webkul\Account\Models\Move;

class OpenInDriveAction
{
    public static function make(): Action
    {
        return Action::make('openInDrive')
            ->label('Open in Drive')
            ->icon('heroicon-o-cloud')
            ->color('success')
            ->visible(function (?Move $record): bool {
                if (! $record) {
                    return false;
                }

                $attachment = $record->documentAttachments()
                    ->with('document.driveSync')
                    ->latest()
                    ->first();

                return (bool) ($attachment?->document?->driveSync?->drive_file_id);
            })
            ->url(function (?Move $record): ?string {
                if (! $record) {
                    return null;
                }

                $attachment = $record->documentAttachments()
                    ->with('document.driveSync')
                    ->latest()
                    ->first();

                $fileId = $attachment?->document?->driveSync?->drive_file_id;

                return $fileId ? "https://drive.google.com/file/d/{$fileId}/view" : null;
            })
            ->openUrlInNewTab();
    }
}
