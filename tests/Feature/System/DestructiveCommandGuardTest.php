<?php

namespace Tests\Feature\System;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/** T-SYS-2: destructive commands only run against Brevon MySQL databases (D-012). */
class DestructiveCommandGuardTest extends TestCase
{
    private function fire(string $command, array $args = []): void
    {
        $input = new ArgvInput(['artisan', $command, ...$args]);

        app(DestructiveCommandGuard::class)->handle(new CommandStarting($command, $input, new NullOutput));
    }

    public function test_it_allows_the_brevon_test_database(): void
    {
        $this->assertSame('brevon_pos_test', config('database.connections.mysql.database'));

        $this->fire('migrate:fresh');

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_any_other_database_name(): void
    {
        config(['database.connections.mysql.database' => 'someone_else_production']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a Brevon database');

        $this->fire('db:wipe');
    }

    public function test_it_refuses_a_non_mysql_connection_given_by_option(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('driver [sqlite]');

        $this->fire('migrate:fresh', ['--database=sqlite']);
    }

    public function test_it_refuses_outside_local_and_testing_environments(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('environment [production]');

        $this->fire('migrate:refresh');
    }

    public function test_it_ignores_non_destructive_commands(): void
    {
        config(['database.connections.mysql.database' => 'someone_else_production']);

        $this->fire('migrate');
        $this->fire('route:list');

        $this->addToAssertionCount(1);
    }

    public function test_it_is_registered_for_artisan_commands(): void
    {
        config(['database.connections.mysql.database' => 'someone_else_production']);

        $this->expectException(RuntimeException::class);

        $this->artisan('db:wipe', ['--force' => true])->run();
    }
}
