<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LocationSearchApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('nominatim-global-search');
    }

    public function test_location_search_requires_authentication(): void
    {
        $this->getJson('/api/locations/search?q=Parque+Central')
            ->assertUnauthorized();
    }

    public function test_location_search_returns_openstreetmap_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'name' => 'Parque Central',
                    'display_name' => 'Parque Central, La Paz, Bolivia',
                    'lat' => '-16.5000',
                    'lon' => '-68.1500',
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/locations/search?q=Parque+Central')
            ->assertOk()
            ->assertJsonPath('locations.0.name', 'Parque Central')
            ->assertJsonPath(
                'locations.0.address',
                'Parque Central, La Paz, Bolivia',
            )
            ->assertJsonPath('locations.0.latitude', -16.5)
            ->assertJsonPath('locations.0.longitude', -68.15);

        Http::assertSent(fn ($request): bool =>
            str_starts_with(
                $request->url(),
                'https://nominatim.openstreetmap.org/search',
            )
            && str_contains($request->header('User-Agent')[0], 'JuntesApp/0.1')
            && $request['format'] === 'jsonv2'
            && $request['limit'] === 5
        );
    }

    public function test_location_search_is_globally_limited_to_one_request_per_second(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/locations/search?q=Parque+Central')
            ->assertOk();
        $this->getJson('/api/locations/search?q=Otro+parque')
            ->assertTooManyRequests();

        Http::assertSentCount(1);
    }
}
