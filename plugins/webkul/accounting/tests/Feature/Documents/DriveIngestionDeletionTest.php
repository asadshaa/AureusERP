<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages\ListDriveIngestionClassifications;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

it('deletes an unposted ingestion and trashes its file in Google Drive', function () {
    $fakeDrive = new FakeDriveClient;
    [$fileId] = $fakeDrive->createFile('INV-101.pdf', 'application/pdf', 'pdf bytes', 'folder-1');
    app()->instance(DriveClient::class, $fakeDrive);

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => $fileId,
        'filename'        => 'INV-101.pdf',
        'mime_type'       => 'application/pdf',
        'file_size'       => 1024,
        'checksum_sha256' => hash('sha256', 'pdf bytes'),
        'status'          => DriveIngestionStatus::Downloaded,
        'discovered_at'   => now(),
    ]);

    $classification = DriveIngestionClassification::query()->create([
        'drive_ingestion_id'       => $ingestion->id,
        'company_id'               => $company->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-101',
        'validation_status'        => DriveClassificationStatus::Valid,
    ]);

    $service = app(DriveIngestionService::class);
    $result = $service->deleteIngestionClassification($classification, deleteFromDrive: true);

    expect($result['success'])->toBeTrue()
        ->and($result['drive_deleted'])->toBeTrue()
        ->and(DriveIngestionClassification::query()->find($classification->id))->toBeNull()
        ->and(DriveIngestion::query()->find($ingestion->id))->toBeNull()
        ->and($fakeDrive->fileExists($fileId))->toBeFalse();
});

it('strictly blocks deletion of an already-posted ingestion', function () {
    $fakeDrive = new FakeDriveClient;
    [$fileId] = $fakeDrive->createFile('INV-102.pdf', 'application/pdf', 'pdf bytes', 'folder-1');
    app()->instance(DriveClient::class, $fakeDrive);

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);

    $journal = Journal::factory()->create([
        'company_id' => $company->id,
        'type'       => JournalType::SALE,
    ]);

    $postedMove = Move::query()->create([
        'company_id'  => $company->id,
        'journal_id'  => $journal->id,
        'currency_id' => $currency->id,
        'creator_id'  => $user->id,
        'name'        => 'INV/2026/001',
        'move_type'   => 'out_invoice',
        'state'       => 'posted',
    ]);

    $ingestion = DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => $fileId,
        'filename'        => 'INV-102.pdf',
        'mime_type'       => 'application/pdf',
        'file_size'       => 1024,
        'checksum_sha256' => hash('sha256', 'pdf bytes'),
        'status'          => DriveIngestionStatus::Downloaded,
        'discovered_at'   => now(),
    ]);

    $classification = DriveIngestionClassification::query()->create([
        'drive_ingestion_id'       => $ingestion->id,
        'company_id'               => $company->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-102',
        'validation_status'        => DriveClassificationStatus::Posted,
        'created_invoice_id'       => $postedMove->id,
    ]);

    $service = app(DriveIngestionService::class);

    expect(fn () => $service->deleteIngestionClassification($classification, deleteFromDrive: true))
        ->toThrow(DomainException::class);

    // Records must remain completely intact
    expect(DriveIngestionClassification::query()->find($classification->id))->not->toBeNull()
        ->and(DriveIngestion::query()->find($ingestion->id))->not->toBeNull()
        ->and($fakeDrive->fileExists($fileId))->toBeTrue();
});

it('supports bulk deleting unposted ingestions while preserving posted ingestions via Filament', function () {
    $fakeDrive = new FakeDriveClient;
    [$unpostedId] = $fakeDrive->createFile('INV-1.pdf', 'application/pdf', 'bytes', 'folder-1');
    [$postedId] = $fakeDrive->createFile('INV-2.pdf', 'application/pdf', 'bytes', 'folder-1');
    app()->instance(DriveClient::class, $fakeDrive);

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $user = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $user->givePermissionTo(AccountingPermissions::ManageDocuments);
    $user->givePermissionTo(AccountingPermissions::ViewDocuments);

    $journal = Journal::factory()->create([
        'company_id' => $company->id,
        'type'       => JournalType::SALE,
    ]);

    $postedMove = Move::query()->create([
        'company_id'  => $company->id,
        'journal_id'  => $journal->id,
        'currency_id' => $currency->id,
        'creator_id'  => $user->id,
        'name'        => 'INV/2026/002',
        'move_type'   => 'out_invoice',
        'state'       => 'posted',
    ]);

    $ingestion1 = DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => $unpostedId,
        'filename'        => 'INV-1.pdf',
        'mime_type'       => 'application/pdf',
        'file_size'       => 1024,
        'checksum_sha256' => hash('sha256', 'bytes1'),
        'status'          => DriveIngestionStatus::Downloaded,
        'discovered_at'   => now(),
    ]);

    $unpostedClass = DriveIngestionClassification::query()->create([
        'drive_ingestion_id'       => $ingestion1->id,
        'company_id'               => $company->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-1',
        'validation_status'        => DriveClassificationStatus::Valid,
    ]);

    $ingestion2 = DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => $postedId,
        'filename'        => 'INV-2.pdf',
        'mime_type'       => 'application/pdf',
        'file_size'       => 1024,
        'checksum_sha256' => hash('sha256', 'bytes2'),
        'status'          => DriveIngestionStatus::Downloaded,
        'discovered_at'   => now(),
    ]);

    $postedClass = DriveIngestionClassification::query()->create([
        'drive_ingestion_id'       => $ingestion2->id,
        'company_id'               => $company->id,
        'document_type'            => DriveDocumentType::CustomerInvoice,
        'extracted_invoice_number' => 'INV-2',
        'validation_status'        => DriveClassificationStatus::Posted,
        'created_invoice_id'       => $postedMove->id,
    ]);

    $this->actingAs($user);

    Livewire::test(ListDriveIngestionClassifications::class)
        ->callTableBulkAction('bulkDelete', [$unpostedClass, $postedClass])
        ->assertHasNoTableActionErrors();

    // unposted was deleted
    expect(DriveIngestionClassification::query()->find($unpostedClass->id))->toBeNull()
        ->and(DriveIngestion::query()->find($ingestion1->id))->toBeNull()
        ->and($fakeDrive->fileExists($unpostedId))->toBeFalse();

    // posted was protected and skipped
    expect(DriveIngestionClassification::query()->find($postedClass->id))->not->toBeNull()
        ->and(DriveIngestion::query()->find($ingestion2->id))->not->toBeNull()
        ->and($fakeDrive->fileExists($postedId))->toBeTrue();
});
