<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;
use Ttpryg\DatabaseMigrator\Exception\InvalidMigrationException;
use Ttpryg\DatabaseMigrator\Exception\MigrationNotFoundException;

/**
 * Incremental schema migration runner backed by a tracker table.
 *
 * Every `*.php` file in the migrations directory is expected to return a
 * {@see Migration} instance and is executed in filename (alphabetical) order.
 * Successfully applied migrations are recorded in the tracker table so
 * `migrate()` can be safely run repeatedly.
 *
 * `rollback()` calls `Migration::down()` for the most recently recorded
 * migrations. Migrations that cannot be reverted safely fail explicitly
 * (see {@see Migration::down()}).
 *
 * MySQL note: DDL is auto-commit, so a failure in the middle of a migration
 * file cannot be rolled back. Migration files should therefore be written to
 * be idempotent (e.g. CREATE TABLE IF NOT EXISTS).
 */
class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        private readonly string $trackerTable = 'migrations',
        private readonly bool $autoCreateTrackerTable = true
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trackerTable) !== 1) {
            throw InvalidMigrationException::invalidTrackerTable($trackerTable);
        }
    }

    /**
     * Run all migrations that have not been applied yet.
     *
     * @return array<int, string> Names of the migrations executed in this run.
     *
     * @throws Throwable
     */
    public function migrate(): array
    {
        $applied = array_flip($this->appliedMigrations());
        $executed = [];

        foreach ($this->discoverMigrations() as $name => $path) {
            $migration = $this->load($path);

            if ($this->isApplied($name, $migration, $applied)) {
                continue;
            }

            // The tracker is only written after a successful run; on failure,
            // the migration is retried automatically on the next run.
            $migration->up($this->pdo);
            $this->record($name);

            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Revert the most recently applied migrations.
     *
     * @param  int  $steps  Number of trailing migrations to revert (default 1).
     * @return array<int, string> Names of the reverted migrations, newest first.
     *
     * @throws Throwable
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new InvalidArgumentException('Rollback steps must be at least 1.');
        }

        $discovered = $this->discoverMigrations();
        $rolledBack = [];

        foreach (array_reverse($this->appliedMigrations()) as $recorded) {
            if (count($rolledBack) === $steps) {
                break;
            }

            $path = $this->locateMigration($recorded, $discovered);

            if ($path === null) {
                throw MigrationNotFoundException::forRollback($recorded);
            }

            $this->load($path)->down($this->pdo);
            $this->removeRecord($recorded);

            $rolledBack[] = $recorded;
        }

        return $rolledBack;
    }

    /**
     * Mark every available migration as applied without executing it.
     *
     * Useful for baseline installs where a schema snapshot already reflects
     * the latest schema.
     *
     * @return array<int, string> Names of the migrations recorded as baseline.
     */
    public function markAllAsMigrated(): array
    {
        $applied = array_flip($this->appliedMigrations());
        $recorded = [];

        foreach ($this->discoverMigrations() as $name => $path) {
            if ($this->isApplied($name, $this->load($path), $applied)) {
                continue;
            }

            $this->record($name);
            $recorded[] = $name;
        }

        return $recorded;
    }

    /**
     * @return array<int, string> Names of the migrations recorded in the tracker.
     */
    public function appliedMigrations(): array
    {
        if ($this->autoCreateTrackerTable) {
            $this->ensureTrackerTable();
        }

        $stmt = $this->pdo->prepare('SELECT migration FROM '.$this->trackerTable.' ORDER BY migration');
        $stmt->execute();

        $migrations = [];
        while (($row = $stmt->fetchColumn()) !== false) {
            $migrations[] = (string) $row;
        }

        return $migrations;
    }

    /**
     * Create the tracker table if it does not already exist.
     */
    public function ensureTrackerTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.$this->trackerTable.' (
                migration VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NULL
            )'
        );
    }

    private function record(string $name): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO '.$this->trackerTable.' (migration, applied_at) VALUES (:migration, :applied_at)'
        );
        $stmt->execute([
            'migration' => $name,
            'applied_at' => (new DateTimeImmutable)->format('Y-m-d H:i:s'),
        ]);
    }

    private function removeRecord(string $name): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM '.$this->trackerTable.' WHERE migration = :migration');
        $stmt->execute(['migration' => $name]);
    }

    /**
     * @param  array<string, int>  $applied
     */
    private function isApplied(string $name, Migration $migration, array $applied): bool
    {
        if (isset($applied[$name])) {
            return true;
        }

        foreach ($migration->previouslyRecordedAs() as $alias) {
            if (isset($applied[$alias])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $discovered
     */
    private function locateMigration(string $recorded, array $discovered): ?string
    {
        if (isset($discovered[$recorded])) {
            return $discovered[$recorded];
        }

        foreach ($discovered as $path) {
            if (in_array($recorded, $this->load($path)->previouslyRecordedAs(), true)) {
                return $path;
            }
        }

        return null;
    }

    private function load(string $path): Migration
    {
        $migration = require $path;

        if (! $migration instanceof Migration) {
            throw InvalidMigrationException::invalidMigrationFile($path, Migration::class);
        }

        return $migration;
    }

    /**
     * @return array<string, string> Map of migration name => file path, sorted alphabetically (asc).
     */
    private function discoverMigrations(): array
    {
        if (! is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath.'/*.php');
        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $migrations[basename($file, '.php')] = $file;
        }

        return $migrations;
    }
}
