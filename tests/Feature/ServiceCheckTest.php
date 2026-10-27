<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ServiceCheckTest extends TestCase
{
    public function test_checks_services_without_mutating_data(): void
    {
        $mysql = Mockery::mock();
        $mysql->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $mysql->shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['1' => 1]]);
        DB::shouldReceive('connection')->twice()->andReturn($mysql);
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->artisan('vault:services:check')
            ->expectsOutput('MySQL: conexiune verificata.')
            ->expectsOutput('Redis: conexiune verificata.')
            ->assertSuccessful();
    }

    public function test_does_not_report_sqlite_as_mysql(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->artisan('vault:services:check')
            ->expectsOutput('MySQL: selecteaza DB_CONNECTION=mysql.')
            ->assertFailed();
    }

    public function test_failure_output_does_not_include_connection_exception_contents(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('PRIVATE-EXAMPLE-DB'));
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('PRIVATE-EXAMPLE-REDIS'));

        $this->artisan('vault:services:check')
            ->expectsOutput('MySQL: conexiune indisponibila. Verifica serviciul si configuratia locala.')
            ->expectsOutput('Redis: conexiune indisponibila. Verifica serviciul si configuratia locala.')
            ->doesntExpectOutputToContain('PRIVATE-EXAMPLE')
            ->assertFailed();
    }
}
