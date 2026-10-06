<?php

namespace App\Filament\Widgets;

use App\Services\EarthquakeFeed;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EarthquakeStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $result = app(EarthquakeFeed::class)->get();

        if (! $result->successful) {
            return [
                Stat::make('Events in feed', 'Unavailable')->color('danger'),
                Stat::make('Largest magnitude', 'Unavailable')->color('danger'),
                Stat::make('Average magnitude', 'Unavailable')->color('danger'),
                Stat::make('Tsunami alerts', 'Unavailable')->color('danger'),
            ];
        }

        $records = collect($result->records);
        $tsunamiCount = $records->where('tsunami', true)->count();

        return [
            Stat::make('Events in feed', (string) $records->count())
                ->description('Live USGS feed')
                ->color('primary'),
            Stat::make('Largest magnitude', number_format((float) $records->max('magnitude'), 1))
                ->description('Past 24 hours')
                ->color('warning'),
            Stat::make('Average magnitude', number_format((float) $records->avg('magnitude'), 1))
                ->description('Past 24 hours')
                ->color('info'),
            Stat::make('Tsunami alerts', (string) $tsunamiCount)
                ->description($tsunamiCount > 0 ? 'Tsunami flag present' : 'No tsunami flags in current feed')
                ->color($tsunamiCount > 0 ? 'danger' : 'success'),
        ];
    }
}
