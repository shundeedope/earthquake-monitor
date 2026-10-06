<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\EarthquakeMagnitudeChart;
use App\Filament\Widgets\EarthquakeStatsOverview;
use App\Filament\Widgets\RecentEarthquakesTable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const DASHBOARD_ENDPOINT = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson';

function fakeDashboardFeature(array $overrides = []): array
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

beforeEach(function () {
    Http::preventStrayRequests();
});

test('an authenticated user can load the admin dashboard', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(['features' => [fakeDashboardFeature()]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Earthquake Monitor')
        ->assertSee('Source: USGS real-time earthquake feed');
});

test('the dashboard contains exactly the three intended widgets', function () {
    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toBe([
        EarthquakeStatsOverview::class,
        EarthquakeMagnitudeChart::class,
        RecentEarthquakesTable::class,
    ]);
});

test('guest access follows the panel default and redirects to login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('stats widget displays count, maximum, average, and tsunami count from the fake response', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(['features' => [
            fakeDashboardFeature(['properties' => ['mag' => 3.0, 'tsunami' => 0]]),
            fakeDashboardFeature(['id' => 'us7000other', 'properties' => ['mag' => 5.0, 'tsunami' => 1]]),
        ]]),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(EarthquakeStatsOverview::class)
        ->assertSee('2')
        ->assertSee('5.0')
        ->assertSee('4.0')
        ->assertSee('1');
});

test('stats widget shows unavailable when the feed fails', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(null, 503),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(EarthquakeStatsOverview::class)
        ->assertSee('Unavailable');
});

test('chart widget returns line and the expected labels and dataset values', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(['features' => [fakeDashboardFeature()]]),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $chart = new EarthquakeMagnitudeChart;
    $chart->mount();

    $data = (fn () => $this->getData())->call($chart);

    expect($chart)->toBeInstanceOf(EarthquakeMagnitudeChart::class)
        ->and((fn () => $this->getType())->call($chart))->toBe('line')
        ->and($data['datasets'][0]['data'])->toBe([4.7])
        ->and($data['labels'])->toHaveCount(1);
});

test('chart widget returns an empty state when the feed is unavailable', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(null, 503),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $chart = new EarthquakeMagnitudeChart;
    $chart->mount();

    $data = (fn () => $this->getData())->call($chart);

    expect($data['datasets'])->toBe([])
        ->and($data['labels'])->toBe([]);
});

test('table widget uses custom array records and displays the expected rows', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(['features' => [fakeDashboardFeature()]]),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(RecentEarthquakesTable::class)
        ->assertSee('35 km south of Example City')
        ->assertSee('4.7');
});

test('table widget renders without an exception when the feed is unavailable', function () {
    Http::fake([
        DASHBOARD_ENDPOINT => Http::response(null, 503),
    ]);

    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(RecentEarthquakesTable::class)
        ->assertOk();
});
