<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\Data\GeofenceDecision;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;

/**
 * Pure decision function: location evidence + candidate fences in, decision
 * out. No database access. Thresholds come from config/hr_attendance_geofence.php.
 */
final class GeofenceEvaluator
{
    public function __construct(private readonly GeoDistanceCalculator $distance) {}

    /**
     * @param  Collection<int, WorkLocation>  $candidates  already filtered to usable, same-company, active fences
     */
    public function evaluate(
        LocationEvidence $evidence,
        Collection $candidates,
        AttendanceVerificationAction $action,
        bool $repeatedCoordinates = false,
        ?CarbonImmutable $now = null,
    ): GeofenceDecision {
        $now ??= CarbonImmutable::now('UTC');
        $flags = [];

        if (! $this->distance->isValidCoordinate($evidence->latitude, $evidence->longitude)
            || ! is_finite($evidence->accuracy) || $evidence->accuracy <= 0) {
            return new GeofenceDecision(Result::InvalidCoordinates, false);
        }

        [$matched, $distanceMeters] = $this->nearestFence($evidence, $candidates);

        if ($evidence->capturedAt === null) {
            $flags[] = 'missing_client_timestamp';
        } else {
            // Age is measured against the device's own clock when the browser
            // sends it (both timestamps then come from the same clock, so a phone
            // whose clock is a few minutes off is not wrongly rejected); otherwise
            // against the server clock. Positive when the reading is in the past.
            $reference = $evidence->clientNow ?? $now;
            $ageSeconds = $evidence->capturedAt->diffInSeconds($reference, false);
            $stale = $ageSeconds > (int) config('hr_attendance_geofence.max_location_age_seconds')
                || -$ageSeconds > (int) config('hr_attendance_geofence.max_clock_skew_seconds');

            if ($stale) {
                // Browsers (desktop Edge/Chrome in particular) can hand back a cached
                // fix even with maximumAge: 0. A stale check-in is refused so the
                // employee retries; a stale check-out is kept but sent for review,
                // because losing the check-out corrupts the day more than a flag does.
                if ($action !== AttendanceVerificationAction::CheckOut
                    || config('hr_attendance_geofence.checkout_without_location') !== 'review') {
                    return new GeofenceDecision(Result::StaleLocation, false, false, $matched, $distanceMeters, $flags);
                }

                $flags[] = 'stale_location';
            }
        }

        if ($evidence->accuracy > (float) config('hr_attendance_geofence.accuracy_reviewable_max')) {
            return new GeofenceDecision(Result::LowAccuracy, false, false, $matched, $distanceMeters, $flags);
        }

        if ($matched === null || $distanceMeters === null) {
            return new GeofenceDecision(Result::NoLocationConfigured, false);
        }

        $inside = $distanceMeters <= (float) $matched->geofence_radius_meters;

        if (! $inside) {
            if ($action === AttendanceVerificationAction::CheckOut
                && config('hr_attendance_geofence.checkout_outside_geofence') === 'review') {
                return new GeofenceDecision(Result::NeedsReview, true, true, $matched, $distanceMeters, [...$flags, 'outside_geofence']);
            }

            return new GeofenceDecision(Result::OutsideGeofence, false, false, $matched, $distanceMeters, $flags);
        }

        if ($evidence->accuracy > (float) config('hr_attendance_geofence.accuracy_verified_max')) {
            $flags[] = 'low_accuracy';
        }
        if ($evidence->accuracy < (float) config('hr_attendance_geofence.accuracy_suspicious_below')) {
            $flags[] = 'suspicious_accuracy';
        }
        if ($repeatedCoordinates) {
            $flags[] = 'repeated_coordinates';
        }

        $needsReview = array_intersect($flags, ['low_accuracy', 'suspicious_accuracy', 'repeated_coordinates', 'stale_location']) !== [];

        return new GeofenceDecision(
            $needsReview ? Result::NeedsReview : Result::Verified,
            true,
            $needsReview,
            $matched,
            $distanceMeters,
            $flags,
        );
    }

    /**
     * @param  Collection<int, WorkLocation>  $candidates
     * @return array{0: ?WorkLocation, 1: ?float}
     */
    private function nearestFence(LocationEvidence $evidence, Collection $candidates): array
    {
        $best = null;
        $bestDistance = null;
        $bestRatio = null;

        foreach ($candidates as $location) {
            $radius = (float) $location->geofence_radius_meters;
            if ($radius <= 0) {
                continue;
            }

            $distance = $this->distance->distanceMeters(
                $evidence->latitude,
                $evidence->longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            );
            $ratio = $distance / $radius;

            if ($bestRatio === null || $ratio < $bestRatio) {
                $best = $location;
                $bestDistance = round($distance, 2);
                $bestRatio = $ratio;
            }
        }

        return [$best, $bestDistance];
    }
}
