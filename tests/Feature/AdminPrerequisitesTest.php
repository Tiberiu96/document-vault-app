<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminPrerequisitesTest extends TestCase
{
    public function test_database_failure_stops_before_prompts_without_exposing_the_exception(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('PRIVATE-EXAMPLE-CONNECTION'));

        $this->artisan('vault:admin:create')
            ->expectsOutput('Baza de date nu este disponibila. Verifica DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME si DB_PASSWORD in .env.')
            ->doesntExpectOutputToContain('PRIVATE-EXAMPLE')
            ->assertFailed();
    }

    public function test_missing_schema_stops_before_password_prompt(): void
    {
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->once()->with('users')->andReturn(false);
        Schema::swap($schema);
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->once()->andReturn(true);
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->artisan('vault:admin:create')
            ->expectsOutput('Schema nu este pregatita. Ruleaza php artisan migrate pe baza Vault configurata.')
            ->assertFailed();
    }
}
