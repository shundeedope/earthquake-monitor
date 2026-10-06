<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EarthquakeMagnitudeChart;
use App\Filament\Widgets\EarthquakeStatsOverview;
use App\Filament\Widgets\RecentEarthquakesTable;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Earthquake Monitor';

    public function getWidgets(): array
    {
        return [
            EarthquakeStatsOverview::class,
            EarthquakeMagnitudeChart::class,
            RecentEarthquakesTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        return 'Source: USGS real-time earthquake feed';
    }
}
