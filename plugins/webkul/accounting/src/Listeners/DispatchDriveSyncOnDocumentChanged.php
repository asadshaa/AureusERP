<?php

namespace Webkul\Accounting\Listeners;

use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Events\DocumentContentChanged;
use Webkul\Accounting\Jobs\SyncDocumentToDriveJob;

/**
 * The only place accounting_drive.enabled is checked before anything
 * Drive-related happens at all -- when it's false, this listener isn't
 * even registered (see AccountingServiceProvider), so a
 * DocumentContentChanged event costs nothing beyond firing.
 */
class DispatchDriveSyncOnDocumentChanged
{
    public function handle(DocumentContentChanged $event): void
    {
        if (! config('accounting_drive.enabled')) {
            return;
        }

        // DocumentService::uploadFromPeer() records this Uploaded audit
        // (with metadata.source) inside the same DB transaction, BEFORE it
        // dispatches DocumentContentChanged -- so it's always committed and
        // visible here, unlike DriveIngestion::document_id, which is only
        // set by DriveIngestionService::register() AFTER uploadFromPeer()
        // (and this listener) has already run, so checking that column here
        // would always see it as still null and never actually skip.
        //
        // Without this check, every file a human drops into the inbound
        // folder gets ingested (Drive -> Aureus) and then immediately
        // re-exported right back (Aureus -> Drive) into a different folder
        // ("Other Documents" rather than "Inbound"), creating a spurious
        // duplicate copy in Drive for every single ingested file. Confirmed
        // live: uploading one PDF to the inbound folder and running
        // discovery produced an unwanted second copy moments later. Export
        // is for documents Aureus itself originates (invoices, bills,
        // journals, ...); a document that just arrived FROM Drive has no
        // reason to be written straight back to it.
        $uploadAudit = $event->document->audits()
            ->where('action', DocumentAuditAction::Uploaded)
            ->oldest()
            ->first();

        if (($uploadAudit?->metadata['source'] ?? null) === 'drive_import') {
            return;
        }

        SyncDocumentToDriveJob::dispatch($event->document->id);
    }
}
