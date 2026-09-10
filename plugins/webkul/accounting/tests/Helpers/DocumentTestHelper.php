<?php

use Illuminate\Http\UploadedFile;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

if (! function_exists('documentTestUser')) {
    function documentTestUser(?Company $company = null, array $permissions = []): User
    {
        $company ??= Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user->refresh();
    }
}

if (! function_exists('fakeUploadedFileWithRealContent')) {
    /**
     * UploadedFile::fake()->create($name, $kilobytes) fakes the reported
     * size but writes an EMPTY physical temp file -- fine for tests that
     * only check metadata (mime/size validation), useless for anything
     * that actually reads bytes back (checksum, tamper detection, version
     * history). This writes genuine content to a real temp file instead.
     */
    function fakeUploadedFileWithRealContent(string $name, string $mimeType, ?string $contents = null): UploadedFile
    {
        $contents ??= 'Fake but real bytes for '.$name.' -- '.bin2hex(random_bytes(16));

        $path = tempnam(sys_get_temp_dir(), 'doc-test-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mimeType, null, true);
    }
}
