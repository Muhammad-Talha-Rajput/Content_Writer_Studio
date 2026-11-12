<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_checks_the_database_and_returns_a_readiness_response(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['1' => 1]]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('app', config('app.name'))
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonStructure(['checked_at'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_database_failure_returns_503_without_exposing_connection_details(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')
            ->andThrow(new RuntimeException('Private connection details must not be returned.'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', 'unavailable')
            ->assertDontSee('Private connection details');
    }

    public function test_liveness_does_not_depend_on_the_database(): void
    {
        DB::shouldReceive('select')->never();
        $this->get('/up')->assertOk();
    }

    public function test_api_allows_the_configured_frontend_origin(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $this->withHeaders(['Origin' => 'http://127.0.0.1:5174'])->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5174');
    }

    public function test_api_does_not_allow_an_unconfigured_origin(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $this->withHeaders(['Origin' => 'https://unrelated.example'])->getJson('/api/health')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
