<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Webkul\Employee\Enums\AttendanceVerificationAction as Action;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;
use Webkul\Employee\Services\Attendance\GeoDistanceCalculator;
use Webkul\Employee\Services\Attendance\GeofenceEvaluator;

function evalFence(float $lat = GEO_LAT, float $lng = GEO_LNG, int $radius = 150, string $name = 'HQ'): WorkLocation
{
    $location = new WorkLocation(['name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'geofence_radius_meters' => $radius, 'geofence_enabled' => true]);
    $location->id = random_int(1, 100000);

    return $location;
}

function evalEvidence(float $lat = GEO_LAT, float $lng = GEO_LNG, float $accuracy = 10, ?CarbonImmutable $capturedAt = null, bool $noTimestamp = false): LocationEvidence
{
    return new LocationEvidence($lat, $lng, $accuracy, $noTimestamp ? null : ($capturedAt ?? CarbonImmutable::now('UTC')), (string) Str::uuid());
}

beforeEach(function () {
    config(['hr_attendance_geofence.enabled' => true]);
    $this->evaluator = app(GeofenceEvaluator::class);
});

it('verifies a precise reading inside the fence', function () {
    $d = $this->evaluator->evaluate(evalEvidence(accuracy: 10), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::Verified)->and($d->accepted)->toBeTrue()->and($d->needsReview)->toBeFalse()
        ->and($d->distanceMeters)->toBe(0.0);
});

it('accepts a mediocre reading inside the fence but flags it for review', function () {
    $d = $this->evaluator->evaluate(evalEvidence(accuracy: 80), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::NeedsReview)->and($d->accepted)->toBeTrue()->and($d->flags)->toContain('low_accuracy');
});

it('rejects a reading whose accuracy is worse than the reviewable limit', function () {
    $d = $this->evaluator->evaluate(evalEvidence(accuracy: 151), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::LowAccuracy)->and($d->accepted)->toBeFalse();
});

it('rejects a check-in outside the fence and records the distance', function () {
    $d = $this->evaluator->evaluate(evalEvidence(GEO_LAT + 0.01, GEO_LNG), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::OutsideGeofence)->and($d->accepted)->toBeFalse()
        ->and($d->distanceMeters)->toBeGreaterThan(1000);
});

it('treats a point exactly on the boundary as inside', function () {
    $fence = evalFence();
    $probe = new GeoDistanceCalculator;
    $exact = $probe->distanceMeters(GEO_LAT, GEO_LNG, GEO_LAT + 0.001, GEO_LNG);
    $fence->geofence_radius_meters = (int) ceil($exact);

    $d = $this->evaluator->evaluate(evalEvidence(GEO_LAT + 0.001, GEO_LNG), collect([$fence]), Action::CheckIn);

    expect($d->result)->toBe(Result::Verified);
});

it('flags a check-out outside the fence for review when configured to review', function () {
    config(['hr_attendance_geofence.checkout_outside_geofence' => 'review']);
    $d = $this->evaluator->evaluate(evalEvidence(GEO_LAT + 0.01, GEO_LNG), collect([evalFence()]), Action::CheckOut);

    expect($d->result)->toBe(Result::NeedsReview)->and($d->accepted)->toBeTrue()->and($d->flags)->toContain('outside_geofence');
});

it('rejects a check-out outside the fence when configured to reject', function () {
    config(['hr_attendance_geofence.checkout_outside_geofence' => 'reject']);
    $d = $this->evaluator->evaluate(evalEvidence(GEO_LAT + 0.01, GEO_LNG), collect([evalFence()]), Action::CheckOut);

    expect($d->result)->toBe(Result::OutsideGeofence)->and($d->accepted)->toBeFalse();
});

it('flags a suspiciously perfect accuracy for review, never as plain verified', function () {
    $d = $this->evaluator->evaluate(evalEvidence(accuracy: 0.5), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::NeedsReview)->and($d->flags)->toContain('suspicious_accuracy');
});

it('flags repeated coordinates for review', function () {
    $d = $this->evaluator->evaluate(evalEvidence(), collect([evalFence()]), Action::CheckIn, repeatedCoordinates: true);

    expect($d->result)->toBe(Result::NeedsReview)->and($d->flags)->toContain('repeated_coordinates');
});

it('rejects stale readings and readings from the future', function (int $offsetSeconds) {
    $now = CarbonImmutable::now('UTC');
    $d = $this->evaluator->evaluate(evalEvidence(capturedAt: $now->addSeconds($offsetSeconds)), collect([evalFence()]), Action::CheckIn, now: $now);

    expect($d->result)->toBe(Result::StaleLocation)->and($d->accepted)->toBeFalse();
})->with([
    'too old'    => [-121],
    'far future' => [61],
]);

it('accepts a reading at the edge of the allowed age and only flags a missing timestamp', function () {
    $now = CarbonImmutable::now('UTC');
    $old = $this->evaluator->evaluate(evalEvidence(capturedAt: $now->subSeconds(120)), collect([evalFence()]), Action::CheckIn, now: $now);
    $none = $this->evaluator->evaluate(evalEvidence(noTimestamp: true), collect([evalFence()]), Action::CheckIn, now: $now);

    expect($old->result)->toBe(Result::Verified)
        ->and($none->accepted)->toBeTrue()->and($none->flags)->toContain('missing_client_timestamp');
});

it('rejects invalid coordinates and non-positive accuracy', function (float $lat, float $lng, float $accuracy) {
    $d = $this->evaluator->evaluate(evalEvidence($lat, $lng, $accuracy), collect([evalFence()]), Action::CheckIn);

    expect($d->result)->toBe(Result::InvalidCoordinates)->and($d->accepted)->toBeFalse();
})->with([
    'null island'    => [0.0, 0.0, 10.0],
    'lat out'        => [95.0, 10.0, 10.0],
    'zero accuracy'  => [GEO_LAT, GEO_LNG, 0.0],
    'negative acc.'  => [GEO_LAT, GEO_LNG, -5.0],
]);

it('matches the fence the point is most inside of when several exist', function () {
    $far = evalFence(GEO_LAT + 0.02, GEO_LNG, 150, 'Far');
    $near = evalFence(GEO_LAT, GEO_LNG, 150, 'Near');

    $d = $this->evaluator->evaluate(evalEvidence(), collect([$far, $near]), Action::CheckIn);

    expect($d->matchedLocation->name)->toBe('Near')->and($d->result)->toBe(Result::Verified);
});

it('cannot verify anything without a fence', function () {
    $d = $this->evaluator->evaluate(evalEvidence(), collect(), Action::CheckIn);

    expect($d->accepted)->toBeFalse()->and($d->result)->toBe(Result::NoLocationConfigured);
});
