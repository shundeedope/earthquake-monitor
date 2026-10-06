<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class EarthquakeFeed
{
    private const string ENDPOINT = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson';

    private const int MAX_RECORDS = 100;

    private ?EarthquakeFeedResult $result = null;

    public function get(): EarthquakeFeedResult
    {
        return $this->result ??= $this->fetch();
    }

    private function fetch(): EarthquakeFeedResult
    {
        Log::info('Fetching earthquake data from USGS', ['url' => self::ENDPOINT]);

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->get(self::ENDPOINT);

            $response->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('USGS earthquake feed unavailable', ['error' => $exception->getMessage()]);

            return EarthquakeFeedResult::unavailable($exception->getMessage());
        }

        $features = $response->json('features');

        if (! is_array($features)) {
            Log::warning('USGS earthquake feed returned an unexpected response format');

            return EarthquakeFeedResult::unavailable('The USGS feed returned an unexpected response format.');
        }

        $records = collect($features)
            ->map(fn (mixed $feature) => $this->normalize($feature))
            ->filter()
            ->take(self::MAX_RECORDS)
            ->values()
            ->all();

        Log::info('Received earthquake data from USGS', ['status' => $response->status(), 'earthquakes' => count($records)]);

        return EarthquakeFeedResult::success($records);
    }

    /**
     * @return array{
     *     id: string,
     *     magnitude: float,
     *     place: string,
     *     occurred_at: CarbonImmutable,
     *     tsunami: bool,
     *     url: string,
     *     longitude: float|null,
     *     latitude: float|null,
     *     depth_km: float|null,
     * }|null
     */
    private function normalize(mixed $feature): ?array
    {
        if (! is_array($feature)) {
            return null;
        }

        $id = $feature['id'] ?? null;
        $properties = $feature['properties'] ?? null;
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if (! is_string($id) || $id === '' || ! is_array($properties) || ! is_array($coordinates)) {
            return null;
        }

        $magnitude = $properties['mag'] ?? null;
        $time = $properties['time'] ?? null;

        if (! is_numeric($magnitude) || ! is_numeric($time)) {
            return null;
        }

        $place = $properties['place'] ?? null;

        return [
            'id' => $id,
            'magnitude' => (float) $magnitude,
            'place' => is_string($place) && $place !== '' ? $place : 'Unknown location',
            'occurred_at' => CarbonImmutable::createFromTimestampMs((int) $time),
            'tsunami' => (bool) ($properties['tsunami'] ?? false),
            'url' => is_string($properties['url'] ?? null) ? $properties['url'] : '',
            'longitude' => is_numeric($coordinates[0] ?? null) ? (float) $coordinates[0] : null,
            'latitude' => is_numeric($coordinates[1] ?? null) ? (float) $coordinates[1] : null,
            'depth_km' => is_numeric($coordinates[2] ?? null) ? (float) $coordinates[2] : null,
        ];
    }
}
