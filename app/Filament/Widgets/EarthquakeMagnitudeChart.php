<?php

namespace App\Filament\Widgets;

use App\Services\EarthquakeFeed;
use Filament\Widgets\ChartWidget;

class EarthquakeMagnitudeChart extends ChartWidget
{
    protected ?string $heading = 'Earthquake magnitude — past 24 hours';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $result = app(EarthquakeFeed::class)->get();

        if (! $result->successful) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $records = collect($result->records)->sortBy('occurred_at')->values();

        return [
            'datasets' => [
                [
                    'label' => 'Magnitude',
                    'data' => $records->pluck('magnitude')->all(),
                ],
            ],
            'labels' => $records->map(fn (array $record) => $record['occurred_at']->format('d M H:i'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
