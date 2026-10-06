<?php

use App\Services\EarthquakeFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const ENDPOINT = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson';

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeFeature(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'us7000example',
        'properties' => [
            'mag' => 4.7,
            'place' => '35 km south of Example City',
            'time' => 1_700_000_000_000,
            'tsunami' => 0,
            'url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/us7000example',
        ],
        'geometry' => [
            'coordinates' => [12.34, 56.78, 10.2],
        ],
    ], $overrides);
}

test('a successful geojson response is normalized into records', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => [fakeFeature()]]),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0]['id'])->toBe('us7000example')
        ->and($result->records[0]['magnitude'])->toBe(4.7)
        ->and($result->records[0]['place'])->toBe('35 km south of Example City')
        ->and($result->records[0]['tsunami'])->toBeFalse()
        ->and($result->records[0]['url'])->toBe('https://earthquake.usgs.gov/earthquakes/eventpage/us7000example');
});

test('unix millisecond timestamps become application date objects', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => [fakeFeature(['properties' => ['time' => 1_700_000_000_000]])]]),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->records[0]['occurred_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->records[0]['occurred_at']->getTimestampMs())->toBe(1_700_000_000_000);
});

test('coordinates map to longitude, latitude, and depth correctly', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => [fakeFeature(['geometry' => ['coordinates' => [12.34, 56.78, 10.2]]])]]),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->records[0]['longitude'])->toBe(12.34)
        ->and($result->records[0]['latitude'])->toBe(56.78)
        ->and($result->records[0]['depth_km'])->toBe(10.2);
});

test('malformed features are ignored safely', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => [
            fakeFeature(),
            ['id' => 'missing-properties'],
            ['id' => 'missing-magnitude', 'properties' => ['time' => 1_700_000_000_000], 'geometry' => ['coordinates' => [1, 2, 3]]],
            'not-an-array',
        ]]),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toHaveCount(1);
});

test('empty features returns an empty successful result', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => []]),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe([]);
});

test('non-2xx responses produce an unavailable result', function () {
    Http::fake([
        ENDPOINT => Http::response(null, 503),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([]);
});

test('connection failures produce an unavailable result', function () {
    Http::fake([
        ENDPOINT => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $result = app(EarthquakeFeed::class)->get();

    expect($result->successful)->toBeFalse();
});

test('the request uses the fixed endpoint', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => []]),
    ]);

    app(EarthquakeFeed::class)->get();

    Http::assertSent(fn (Request $request) => $request->url() === ENDPOINT);
});

test('the request is not repeated unnecessarily within one service scope', function () {
    Http::fake([
        ENDPOINT => Http::response(['features' => [fakeFeature()]]),
    ]);

    $feed = app(EarthquakeFeed::class);
    $feed->get();
    $feed->get();

    Http::assertSentCount(1);
});
