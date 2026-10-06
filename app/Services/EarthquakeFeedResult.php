<?php

namespace App\Services;

/**
 * @phpstan-type EarthquakeRecord array{
 *     id: string,
 *     magnitude: float,
 *     place: string,
 *     occurred_at: \Carbon\CarbonImmutable,
 *     tsunami: bool,
 *     url: string,
 *     longitude: float|null,
 *     latitude: float|null,
 *     depth_km: float|null,
 * }
 */
final readonly class EarthquakeFeedResult
{
    /**
     * @param  array<int, EarthquakeRecord>  $records
     */
    private function __construct(
        public bool $successful,
        public array $records = [],
        public ?string $error = null,
    ) {}

    /**
     * @param  array<int, EarthquakeRecord>  $records
     */
    public static function success(array $records): self
    {
        return new self(successful: true, records: $records);
    }

    public static function unavailable(?string $error = null): self
    {
        return new self(successful: false, error: $error);
    }
}
