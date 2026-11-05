<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Refuses destructive artisan commands unless the environment and the
 * target database are explicitly allowed in config('brevon.destructive_guard').
 *
 * @see docs/DECISIONS.md D-012
 */
class DestructiveCommandGuard
{
    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, $this->config('commands'), true)) {
            return;
        }

        $this->assertAllowed($event->command, $this->connectionFrom($event));
    }

    public function assertAllowed(string $command, ?string $connection = null): void
    {
        $environment = (string) app()->environment();
        $connection ??= (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        if (! in_array($environment, $this->config('environments'), true)) {
            throw new RuntimeException(
                "Refusing to run [{$command}]: environment [{$environment}] is not allowed for destructive database commands."
            );
        }

        if (! in_array($driver, $this->config('drivers'), true)) {
            throw new RuntimeException(
                "Refusing to run [{$command}]: driver [{$driver}] on connection [{$connection}] is not allowed for destructive database commands."
            );
        }

        if (! in_array($database, $this->config('databases'), true)) {
            throw new RuntimeException(
                "Refusing to run [{$command}]: database [{$database}] on connection [{$connection}] is not a Brevon database."
            );
        }
    }

    private function connectionFrom(CommandStarting $event): ?string
    {
        // The input is not bound to the command definition yet, so read the
        // raw option (supports "--database=x" and "--database x").
        $value = $event->input->getParameterOption('--database', null, true);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function config(string $key): array
    {
        /** @var list<string> $values */
        $values = config("brevon.destructive_guard.{$key}", []);

        return $values;
    }
}
