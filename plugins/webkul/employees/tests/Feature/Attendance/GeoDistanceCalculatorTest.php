<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Webkul\Employee\Services\Attendance\GeoDistanceCalculator;

beforeEach(fn () => $this->calc = new GeoDistanceCalculator);

it('returns zero for identical points', function () {
    expect($this->calc->distanceMeters(24.8607, 67.0011, 24.8607, 67.0011))->toBe(0.0);
});

it('matches a known long-distance pair (Karachi to Lahore is about 1,033 km by great circle)', function () {
    $km = $this->calc->distanceMeters(24.8607, 67.0011, 31.5204, 74.3587) / 1000;

    expect($km)->toBeGreaterThan(1032)->toBeLessThan(1034);
});

it('measures a 0.0009 degree latitude step as about 100 m', function () {
    $meters = $this->calc->distanceMeters(GEO_LAT, GEO_LNG, GEO_LAT + 0.0009, GEO_LNG);

    expect($meters)->toBeGreaterThan(99.5)->toBeLessThan(100.7);
});

it('handles the antimeridian without wrapping the long way round', function () {
    $meters = $this->calc->distanceMeters(10.0, 179.9999, 10.0, -179.9999);

    expect($meters)->toBeLessThan(50);
});

it('does not produce NaN for near-antipodal points', function () {
    $meters = $this->calc->distanceMeters(0.0001, 0.0001, -0.0001, 179.9999);

    expect(is_nan($meters))->toBeFalse()->and($meters)->toBeGreaterThan(20_000_000);
});

it('rejects invalid coordinates', function (mixed $lat, mixed $lng) {
    expect($this->calc->isValidCoordinate($lat, $lng))->toBeFalse();
})->with([
    'nan'          => [NAN, 10],
    'infinite'     => [INF, 10],
    'lat too high' => [91, 10],
    'lat too low'  => [-91, 10],
    'lng too high' => [10, 181],
    'lng too low'  => [10, -181],
    'string'       => ['abc', 10],
    'null island'  => [0, 0],
    'null'         => [null, null],
]);

it('accepts valid coordinates including the boundaries', function (mixed $lat, mixed $lng) {
    expect($this->calc->isValidCoordinate($lat, $lng))->toBeTrue();
})->with([
    'office'       => [24.8607, 67.0011],
    'north pole'   => [90, 10],
    'south pole'   => [-90, 10],
    'antimeridian' => [10, 180],
    'numeric str'  => ['24.5', '67.5'],
]);
