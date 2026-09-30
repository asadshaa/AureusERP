<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Company;

class AttendanceVerificationFactory extends Factory
{
    protected $model = AttendanceVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'employee_id'        => Employee::factory(),
            'action'             => AttendanceVerificationAction::CheckIn,
            'method'             => AttendanceVerificationMethod::Gps,
            'result'             => AttendanceVerificationResult::Verified,
            'accepted'           => true,
            'latitude'           => 24.8607,
            'longitude'          => 67.0011,
            'accuracy_meters'    => 10.0,
            'client_request_id'  => (string) Str::uuid(),
            'server_recorded_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'accepted' => false,
            'result'   => AttendanceVerificationResult::OutsideGeofence,
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (): array => [
            'result'        => AttendanceVerificationResult::NeedsReview,
            'review_status' => 'pending',
            'flags'         => ['suspicious_accuracy'],
        ]);
    }

    public function checkOut(): static
    {
        return $this->state(fn (): array => [
            'action' => AttendanceVerificationAction::CheckOut,
        ]);
    }

    public function remote(): static
    {
        return $this->state(fn (): array => [
            'method'    => AttendanceVerificationMethod::None,
            'result'    => AttendanceVerificationResult::RemoteExempt,
            'latitude'  => null,
            'longitude' => null,
        ]);
    }
}
