# External API Dashboard

This project demonstrates how to build a **custom Filament dashboard powered by an external API** instead of Eloquent models. It loads the USGS real-time earthquake feed and turns the latest events into summary statistics, a magnitude chart, and a paginated table with links to the original USGS event pages.

The key idea is that every widget reads from one scoped `EarthquakeFeed` service. The service fetches and normalizes the GeoJSON response once per request, so the stats, chart, and table can share the same data without making three separate API calls. The dashboard also handles an unavailable feed gracefully instead of breaking the whole panel.

The repository contains the complete Laravel + Filament project to demonstrate the functionality, including a seeded administrator account and tests for the API service and dashboard widgets.

The Filament project is in the `app/Filament` folder.

Feel free to pick the parts that you actually need in your projects.

---

## How to install

- Clone the repository with `git clone`
- Copy the `.env.example` file to `.env` and edit database credentials there (the default is SQLite)
- Run `composer install`
- Run `php artisan key:generate`
- Run `php artisan storage:link`
- Run `php artisan migrate --seed`
- That's it: launch the URL `/admin` and log in with credentials `admin@admin.com` and `password`

---

## Screenshots

![](https://laraveldaily.com/uploads/2026/08/filamentexamples-api-dashboard-1.png)

![](https://laraveldaily.com/uploads/2026/08/filamentexamples-api-dashboard-2.png)

---

## How It Works

The application is organized around one custom Filament dashboard. The page decides which widgets appear and how they are laid out, while a small service fetches the live data that every widget presents in a different way.

### 1. Dashboard — Custom Title, Layout, and Widgets

The custom dashboard extends Filament's base dashboard page. It changes the page title and subheading, explicitly returns the three earthquake widgets in display order, and uses a two-column grid. The chart and table set their own column span to `full`, so only the summary stats use the multi-column layout.

**app/Filament/Pages/Dashboard.php**

```php
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
```

The custom page replaces the default dashboard by being registered in the panel provider:

**app/Providers/Filament/AdminPanelProvider.php**

```php
->pages([
    Dashboard::class,
])
```

The key points:

- **The dashboard owns the widget order**, so the stats always appear before the chart and recent-events table
- **`getColumns()` creates the two-column page grid**, while widgets that need more horizontal space use `protected int|string|array $columnSpan = 'full'`
- **The page title and subheading explain what the dashboard monitors and identify USGS as the data source**
- **The custom dashboard is registered directly in the panel provider**, replacing Filament's default dashboard page

### 2. EarthquakeFeed — One Normalized API Result

`EarthquakeFeed` calls the USGS `all_day.geojson` endpoint with short connection and response timeouts. It validates each feature, converts the millisecond timestamp into a `CarbonImmutable` instance, extracts coordinates and depth, and limits the result to 100 records. Connection errors, unsuccessful responses, and unexpected payloads become an unavailable `EarthquakeFeedResult` instead of escaping into the widgets.

**app/Services/EarthquakeFeed.php**

```php
public function get(): EarthquakeFeedResult
{
    return $this->result ??= $this->fetch();
}

private function fetch(): EarthquakeFeedResult
{
    try {
        $response = Http::timeout(5)
            ->connectTimeout(3)
            ->get(self::ENDPOINT);

        $response->throw();
    } catch (ConnectionException|RequestException $exception) {
        return EarthquakeFeedResult::unavailable($exception->getMessage());
    }

    // Normalize the USGS GeoJSON features...
}
```

The service is registered as scoped, and its `get()` method memoizes the result. This lets every widget resolve the same service and reuse one API response during the request.

### 3. Stats Overview — Live Feed Totals

The stats widget turns the normalized records into four headline values: the number of events in the feed, the largest magnitude, the average magnitude, and the number of earthquakes carrying a tsunami flag.

**app/Filament/Widgets/EarthquakeStatsOverview.php**

```php
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
```

The key points:

- **The widget calculates all four values from the same collection**, keeping the summaries consistent with the chart and table
- **The tsunami stat changes its description and color dynamically**, using danger when at least one event has the tsunami flag
- **Magnitudes are formatted to one decimal place**, matching the way earthquake magnitudes are normally presented
- **If the feed cannot be loaded, every stat displays `Unavailable` in the danger color**, leaving the dashboard usable while clearly showing the problem
- **Widget polling is disabled**, so Filament does not repeatedly call the external feed in the background

### 4. Magnitude Chart — Events Across the Past 24 Hours

The chart widget sorts the records by occurrence time and maps them into a full-width line chart. Magnitudes become the dataset values, while formatted timestamps become the horizontal labels.

**app/Filament/Widgets/EarthquakeMagnitudeChart.php**

```php
$records = collect($result->records)->sortBy('occurred_at')->values();

return [
    'datasets' => [
        [
            'label' => 'Magnitude',
            'data' => $records->pluck('magnitude')->all(),
        ],
    ],
    'labels' => $records
        ->map(fn (array $record) => $record['occurred_at']->format('d M H:i'))
        ->all(),
];
```

The key points:

- **Sorting by `occurred_at` produces a chronological timeline**, even if the API returns the newest event first
- **The chart uses Filament's `ChartWidget` with the `line` type**, so no custom chart JavaScript is required
- **A maximum height of 300 pixels keeps the dashboard compact**, while the full-width span gives the timeline enough room
- **An unavailable feed returns empty datasets and labels**, allowing the widget to render safely without invented fallback data
- **Polling is disabled here as well**, avoiding automatic repeated calls to the external service

### 5. Recent Earthquakes — Custom Records and a Record Action

The table is built from custom array records rather than an Eloquent query. Its `->records()` callback returns the normalized records from `EarthquakeFeed`, and the columns read array keys such as `occurred_at`, `magnitude`, `place`, `depth_km`, and `tsunami` exactly like normal table state.

**app/Filament/Widgets/RecentEarthquakesTable.php**

```php
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
    ]);
```

Because each record is an array, the record action also receives an array. It reads the normalized `url` value, opens the matching USGS event page in a new tab, and stays hidden when an event has no valid URL.

```php
->recordActions([
    Action::make('view')
        ->label('View')
        ->icon(Heroicon::ArrowTopRightOnSquare)
        ->color('gray')
        ->url(fn (array $record): string => $record['url'])
        ->openUrlInNewTab()
        ->visible(fn (array $record): bool => filled($record['url'])),
])
```

The key points:

- **`->records()` allows a Filament table to display custom API data**, so no earthquake model or database table is needed
- **Column formatters work with the normalized array values**, including a Carbon date object, formatted magnitude and depth, and a boolean tsunami icon
- **The custom record action type-hints `$record` as an array**, then uses its `url` key instead of expecting an Eloquent model
- **`->openUrlInNewTab()` keeps the dashboard open while showing the complete event on USGS**, and `->visible()` prevents an empty link from being rendered
- **The table is limited to ten records per page**, keeping a large real-time feed easy to scan
- **When the service is unavailable, the callback returns an empty collection**, so the table renders normally with no rows

That's it — one custom dashboard, one reusable API service, and three Filament widgets turn a public GeoJSON feed into a complete real-time monitoring interface without storing the earthquake data locally.
