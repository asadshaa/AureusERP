<?php

namespace Webkul\Accounting\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Accounting\Contracts\DocumentStorageProvider;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\DocumentVersion;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;

/**
 * The single entry point for everything document-related. Every method
 * that reads or writes a Document takes the acting $user explicitly and
 * enforces company isolation and permissions itself -- callers (a future
 * Filament resource, a console command, a test) are never trusted to have
 * scoped a query correctly on their own.
 *
 * Company isolation follows the exact pattern already used by
 * InvoiceResource/BillResource/etc. elsewhere in this codebase: scope by
 * the acting user's default_company_id, not an ad-hoc "current company"
 * concept invented for this feature.
 */
class DocumentService
{
    /** 20 MB -- generous for a scanned receipt or a multi-page statement PDF, small enough to keep uploads fast. */
    public const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly DocumentStorageProvider $storage,
    ) {}

    /**
     * Upload a brand new document (its first version).
     */
    public function upload(
        User $user,
        int $companyId,
        DocumentType $documentType,
        string $title,
        ?string $description,
        UploadedFile $file,
        ?string $ipAddress = null,
    ): Document {
        $this->assertCompanyAccess($user, $companyId);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $companyId, null);
        $this->validateFile($file);

        return DB::transaction(function () use ($user, $companyId, $documentType, $title, $description, $file, $ipAddress) {
            $document = Document::create([
                'company_id'    => $companyId,
                'creator_id'    => $user->id,
                'document_type' => $documentType,
                'title'         => $title,
                'description'   => $description,
                'status'        => DocumentStatus::Active,
            ]);

            $version = $this->storeVersion($document, $file, $user, 1, null);

            $document->update(['current_version_id' => $version->id]);

            $this->recordAudit($document, $user, DocumentAuditAction::Uploaded, $ipAddress, [
                'version_id' => $version->id,
                'filename'   => $version->original_filename,
            ]);

            return $document->refresh();
        });
    }

    /**
     * Add a new version to an existing document. The prior version is
     * never touched or deleted -- accounting evidence keeps its full
     * history.
     */
    public function addVersion(
        User $user,
        Document $document,
        UploadedFile $file,
        ?string $changeReason = null,
        ?string $ipAddress = null,
    ): DocumentVersion {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);
        $this->validateFile($file);

        return DB::transaction(function () use ($user, $document, $file, $changeReason, $ipAddress) {
            $nextVersionNumber = (int) $document->versions()->max('version_number') + 1;

            $version = $this->storeVersion($document, $file, $user, $nextVersionNumber, $changeReason);

            $document->update(['current_version_id' => $version->id]);

            $this->recordAudit($document, $user, DocumentAuditAction::VersionAdded, $ipAddress, [
                'version_id'     => $version->id,
                'version_number' => $version->version_number,
                'filename'       => $version->original_filename,
                'change_reason'  => $changeReason,
            ]);

            return $version;
        });
    }

    /**
     * Attach an already-uploaded document to an accounting record
     * (invoice, bill, journal entry, bank statement line, ...). Refuses
     * if the record belongs to a different company than the document --
     * a document must never be usable as evidence for another company's
     * transaction.
     */
    public function attach(User $user, Document $document, Model $record, ?string $note = null, ?string $ipAddress = null): DocumentAttachment
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $recordCompanyId = $record->getAttribute('company_id');

        if ($recordCompanyId !== null && (int) $recordCompanyId !== (int) $document->company_id) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'              => 'cross_company_attachment',
                'attachable_type'     => $record::class,
                'attachable_id'       => $record->getKey(),
                'attachable_company'  => $recordCompanyId,
            ]);

            throw new RuntimeException(
                "This document belongs to a different company than the record you're attaching it to, so it can't be used as evidence for it. ".
                'Upload a separate document under the correct company instead.'
            );
        }

        $attachment = DocumentAttachment::create([
            'company_id'      => $document->company_id,
            'document_id'     => $document->id,
            'attachable_type' => $record::class,
            'attachable_id'   => $record->getKey(),
            'creator_id'      => $user->id,
            'note'            => $note,
        ]);

        $this->recordAudit($document, $user, DocumentAuditAction::Attached, $ipAddress, [
            'attachable_type' => $record::class,
            'attachable_id'   => $record->getKey(),
        ]);

        return $attachment;
    }

    public function detach(User $user, DocumentAttachment $attachment, ?string $ipAddress = null): void
    {
        $document = $attachment->document;

        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $this->recordAudit($document, $user, DocumentAuditAction::Detached, $ipAddress, [
            'attachable_type' => $attachment->attachable_type,
            'attachable_id'   => $attachment->attachable_id,
        ]);

        $attachment->delete();
    }

    /**
     * Look up a single document scoped to the acting user's own company.
     * A document that exists but belongs to another company is treated
     * IDENTICALLY to one that doesn't exist at all -- same exception,
     * same message -- so a caller can never distinguish "wrong company"
     * from "no such id" by probing ids. The attempt is still audited
     * against the document's real company if it does exist elsewhere,
     * so that company's own audit trail shows the attempt.
     */
    public function find(User $user, int $documentId): Document
    {
        $companyId = $user->default_company_id;

        $document = Document::query()->forCompany($companyId)->find($documentId);

        if ($document) {
            return $document;
        }

        $elsewhere = Document::query()->find($documentId);

        if ($elsewhere) {
            $this->recordAudit($elsewhere, $user, DocumentAuditAction::AccessDenied, null, [
                'reason' => 'cross_company_lookup',
            ]);
        }

        throw new ModelNotFoundException('Document not found.');
    }

    /**
     * List documents for the acting user's own company only.
     *
     * @return Collection<int, Document>
     */
    public function listForUser(User $user, array $filters = [])
    {
        $companyId = $user->default_company_id;

        $this->assertPermission($user, AccountingPermissions::ViewDocuments, $companyId, null);

        $query = Document::query()->forCompany($companyId);

        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    /**
     * Read the current version's bytes back out. This is the one place
     * that actually touches file content after upload, so it's also
     * where a checksum mismatch (file corrupted or tampered with at rest)
     * gets caught before handing anything back to the caller.
     */
    public function retrieveContents(User $user, int $documentId, ?string $ipAddress = null): array
    {
        $document = $this->find($user, $documentId);

        $this->assertPermission($user, AccountingPermissions::DownloadDocuments, $document->company_id, $document);

        $version = $document->currentVersion;

        if (! $version) {
            throw new RuntimeException("Document \"{$document->title}\" has no uploaded file yet.");
        }

        if (! $this->storage->exists($version->storage_path)) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'      => 'missing_object',
                'version_id'  => $version->id,
            ]);

            throw new RuntimeException(
                "The file for \"{$document->title}\" is missing from storage even though the record exists. ".
                'This needs investigating before the document can be treated as available -- do not assume it is safe to re-upload silently.'
            );
        }

        $contents = $this->storage->get($version->storage_path);

        if (hash('sha256', $contents) !== $version->checksum_sha256) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'     => 'checksum_mismatch',
                'version_id' => $version->id,
            ]);

            throw new RuntimeException(
                "The stored file for \"{$document->title}\" does not match its recorded checksum. ".
                'The file may have been altered outside the application. Treat it as untrustworthy until reviewed.'
            );
        }

        $this->recordAudit($document, $user, DocumentAuditAction::Downloaded, $ipAddress, [
            'version_id' => $version->id,
        ]);

        return [
            'contents' => $contents,
            'version'  => $version,
            'document' => $document,
        ];
    }

    public function archive(User $user, Document $document, ?string $ipAddress = null): Document
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::DeleteDocuments, $document->company_id, $document);

        $document->update(['status' => DocumentStatus::Archived]);

        $this->recordAudit($document, $user, DocumentAuditAction::Archived, $ipAddress);

        return $document->refresh();
    }

    public function restore(User $user, Document $document, ?string $ipAddress = null): Document
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $document->update(['status' => DocumentStatus::Active]);

        $this->recordAudit($document, $user, DocumentAuditAction::Restored, $ipAddress);

        return $document->refresh();
    }

    private function storeVersion(Document $document, UploadedFile $file, User $user, int $versionNumber, ?string $changeReason): DocumentVersion
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Could not read the uploaded file -- it may have failed to upload completely. Please try again.');
        }

        $checksum = hash('sha256', $contents);
        $path = $this->buildStoragePath($document, $file, $versionNumber);

        if (! $this->storage->put($path, $contents)) {
            throw new RuntimeException('Could not save the document to storage. Nothing was recorded -- please try again.');
        }

        $storedSize = $this->storage->size($path);

        if ($storedSize !== strlen($contents)) {
            // Don't leave a corrupt, half-written object sitting in storage
            // with no record pointing at it and no record NOT pointing at
            // it either -- clean it up before surfacing the failure.
            $this->storage->delete($path);

            throw new RuntimeException(
                "The document was written to storage but came back a different size than what was uploaded ({$storedSize} vs ".strlen($contents).' bytes). '.
                'Nothing was recorded. Please try uploading again.'
            );
        }

        return DocumentVersion::create([
            'document_id'       => $document->id,
            'version_number'    => $versionNumber,
            'storage_disk'      => 'accounting_documents',
            'storage_path'      => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getMimeType() ?: $file->getClientMimeType(),
            'file_size'         => $storedSize,
            'checksum_sha256'   => $checksum,
            'uploaded_by'       => $user->id,
            'change_reason'     => $changeReason,
        ]);
    }

    private function buildStoragePath(Document $document, UploadedFile $file, int $versionNumber): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');

        return sprintf(
            'companies/%d/%s/documents/%d/v%d-%s.%s',
            $document->company_id,
            now()->format('Y'),
            $document->id,
            $versionNumber,
            (string) Str::uuid(),
            $extension,
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The uploaded file did not transfer correctly. Please try again.');
        }

        if ($file->getSize() === false || $file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            $maxMb = self::MAX_FILE_SIZE_BYTES / (1024 * 1024);

            throw new RuntimeException("This file is larger than the {$maxMb}MB limit for accounting documents. Reduce its size and try again.");
        }

        // getMimeType() sniffs the file's actual bytes (via PHP's fileinfo
        // extension); getClientMimeType() is just whatever Content-Type the
        // browser/OS decided to send, which is frequently wrong or generic
        // (a real PDF reported as "application/octet-stream" is common on
        // Windows) and, in a real deployment, trivially spoofable by the
        // client. Only fall back to the client-reported value if PHP
        // genuinely couldn't sniff anything.
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException(
                "\"{$mimeType}\" isn't an accepted file type for accounting documents. ".
                'Upload a PDF, image (JPEG/PNG/WEBP), Word document, or Excel/CSV spreadsheet instead.'
            );
        }
    }

    /**
     * A company mismatch here means the ACTING USER's own default company
     * doesn't match the company the document/action belongs to -- distinct
     * from find()'s "does this document exist under my company" check,
     * this guards write paths where a Document object was already handed
     * to the service (so the caller must not have been able to pass one
     * belonging to another company in the first place, but this is the
     * backstop if they somehow did).
     */
    private function assertCompanyAccess(User $user, int $companyId): void
    {
        if ((int) $user->default_company_id !== (int) $companyId) {
            throw new RuntimeException("You don't have access to documents for this company.");
        }
    }

    private function assertPermission(User $user, string $permission, int $companyId, ?Document $document): void
    {
        if ($user->can($permission)) {
            return;
        }

        if ($document) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, null, [
                'reason'     => 'missing_permission',
                'permission' => $permission,
            ]);
        }

        throw new RuntimeException("You don't have permission to do that with accounting documents.");
    }

    private function recordAudit(Document $document, User $user, DocumentAuditAction $action, ?string $ipAddress = null, array $metadata = []): void
    {
        $document->audits()->create([
            'company_id'  => $document->company_id,
            'actor_id'    => $user->id,
            'action'      => $action,
            'metadata'    => $metadata ?: null,
            'ip_address'  => $ipAddress,
        ]);
    }
}
