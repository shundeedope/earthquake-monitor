<?php

namespace App\Filament\Widgets;

use App\Services\EarthquakeFeed;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

class RecentEarthquakesTable extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent earthquakes')
            ->records(function (): Collection {
                $result = app(EarthquakeFeed::class)->get();

                return collect($result->records);
            })
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Occurred at')
                    ->formatStateUsing(fn (CarbonImmutable $state) => $state->format('d M Y, H:i')),
                TextColumn::make('magnitude')
                    ->formatStateUsing(fn (float $state) => number_format($state, 1)),
                TextColumn::make('place'),
                TextColumn::make('depth_km')
                    ->label('Depth')
                    ->formatStateUsing(fn (?float $state) => $state === null ? '—' : number_format($state, 1).' km'),
                IconColumn::make('tsunami')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (array $record): string => $record['url'])
                    ->openUrlInNewTab()
                    ->visible(fn (array $record): bool => filled($record['url'])),
            ])
            ->defaultPaginationPageOption(10)
            ->paginated([10]);
    }
}
