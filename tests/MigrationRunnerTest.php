<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator\Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\DatabaseMigrator\Exception\InvalidMigrationException;
use Ttpryg\DatabaseMigrator\Exception\MigrationException;
use Ttpryg\DatabaseMigrator\Exception\MigrationNotFoundException;
use Ttpryg\DatabaseMigrator\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;

    private string $directory;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->directory = sys_get_temp_dir().'/database_migrator_migrations_'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_migrate_executes_migrations_in_alphabetical_order(): void
    {
        $this->addMigration('202601010000_first');
        $this->addMigration('202601010001_second');

        $executed = $this->runner()->migrate();

        $this->assertSame(['202601010000_first', '202601010001_second'], $executed);
    }

    public function test_migrate_is_idempotent(): void
    {
        $this->addMigration('202601010000_first');
        $runner = $this->runner();

        $runner->migrate();

        $this->assertSame([], $runner->migrate());
        $this->assertSame(['202601010000_first'], $runner->appliedMigrations());
    }

    public function test_rollback_reverts_migrations_in_reverse_order(): void
    {
        $this->addMigration('202601010000_first');
        $this->addMigration('202601010001_second');
        $runner = $this->runner();

        $runner->migrate();
        $rolledBack = $runner->rollback(2);

        $this->assertSame(['202601010001_second', '202601010000_first'], $rolledBack);
        $this->assertSame([], $runner->appliedMigrations());
    }

    public function test_rollback_without_applied_migrations_returns_empty(): void
    {
        $this->addMigration('202601010000_first');

        $this->assertSame([], $this->runner()->rollback());
    }

    public function test_missing_migrations_directory_is_treated_as_empty(): void
    {
        $migrationRunner = new MigrationRunner($this->pdo, $this->directory.'/does-not-exist');

        $this->assertSame([], $migrationRunner->migrate());
    }

    public function test_invalid_migration_file_throws(): void
    {
        file_put_contents($this->directory.'/202601010000_invalid.php', '<?php return new stdClass;');

        $this->expectException(InvalidMigrationException::class);
        $this->expectExceptionMessage('must return an instance of');

        $this->runner()->migrate();
    }

    public function test_mark_all_as_migrated_records_without_executing(): void
    {
        $this->addMigration(
            '202601010000_create_widgets',
            '$pdo->exec(\'CREATE TABLE widgets (id INTEGER PRIMARY KEY)\');'
        );

        $runner = $this->runner();
        $recorded = $runner->markAllAsMigrated();

        $this->assertSame(['202601010000_create_widgets'], $recorded);
        $this->assertSame(['202601010000_create_widgets'], $runner->appliedMigrations());
        $this->assertFalse($this->tableExists('widgets'));
    }

    public function test_migration_recorded_under_legacy_name_is_skipped(): void
    {
        $this->addMigration(
            '202601010000_create_widgets',
            '$pdo->exec(\'CREATE TABLE widgets (id INTEGER PRIMARY KEY)\');',
            '// no-op',
            "['001_create_widgets']"
        );

        $runner = $this->runner();
        $runner->appliedMigrations();
        $this->pdo->exec("INSERT INTO migrations (migration) VALUES ('001_create_widgets')");

        $this->assertSame([], $runner->migrate());
        $this->assertFalse($this->tableExists('widgets'));
    }

    public function test_rollback_finds_migration_by_legacy_name(): void
    {
        $this->addMigration(
            '202601010000_create_widgets',
            '$pdo->exec(\'CREATE TABLE widgets (id INTEGER PRIMARY KEY)\');',
            '$pdo->exec(\'DROP TABLE widgets\');',
            "['001_create_widgets']"
        );

        $runner = $this->runner();
        $runner->appliedMigrations();
        $this->pdo->exec("INSERT INTO migrations (migration) VALUES ('001_create_widgets')");
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $this->assertSame(['001_create_widgets'], $runner->rollback());
        $this->assertFalse($this->tableExists('widgets'));
    }

    public function test_custom_tracker_table_is_used(): void
    {
        $this->addMigration('202601010000_first');

        $migrationRunner = new MigrationRunner($this->pdo, $this->directory, 'audit_engine_migrations');
        $migrationRunner->migrate();

        $this->assertSame(['202601010000_first'], $migrationRunner->appliedMigrations());
        $this->assertTrue($this->tableExists('audit_engine_migrations'));
    }

    public function test_auto_create_tracker_table_can_be_disabled(): void
    {
        $runner = new MigrationRunner(
            $this->pdo,
            $this->directory,
            'custom_migrations',
            false
        );

        $this->assertFalse($this->tableExists('custom_migrations'));

        $this->expectException(\PDOException::class);
        $runner->appliedMigrations();
    }

    public function test_ensure_tracker_table_can_be_called_explicitly(): void
    {
        $runner = new MigrationRunner(
            $this->pdo,
            $this->directory,
            'custom_migrations',
            false
        );

        $this->assertFalse($this->tableExists('custom_migrations'));

        $runner->ensureTrackerTable();

        $this->assertTrue($this->tableExists('custom_migrations'));
        $this->assertSame([], $runner->appliedMigrations());
    }

    public function test_invalid_tracker_table_name_is_rejected(): void
    {
        $this->expectException(InvalidMigrationException::class);
        $this->expectExceptionMessage('must be a valid SQL identifier');

        new MigrationRunner($this->pdo, $this->directory, 'migrations; DROP TABLE users');
    }

    public function test_rollback_requires_a_positive_step_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rollback steps must be at least 1.');

        $this->runner()->rollback(0);
    }

    public function test_rollback_fails_when_migration_file_is_missing(): void
    {
        $runner = $this->runner();
        $runner->appliedMigrations();
        $this->pdo->exec("INSERT INTO migrations (migration) VALUES ('999999999999_missing')");

        $this->expectException(MigrationNotFoundException::class);
        $this->expectExceptionMessage("Cannot roll back migration '999999999999_missing': migration file not found.");

        $runner->rollback();
    }

    public function test_rollback_fails_when_migration_has_no_down_implementation(): void
    {
        $this->addMigrationWithoutRollback('202601010000_create_widgets');

        $runner = $this->runner();
        $runner->migrate();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('does not support rollback.');

        $runner->rollback();
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->directory);
    }

    private function addMigration(
        string $name,
        string $up = '// no-op',
        string $down = '// no-op',
        string $previouslyRecordedAs = '[]'
    ): void {
        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

return new class extends Migration
{
    public function up(PDO $pdo): void
    {
        %s
    }

    public function down(PDO $pdo): void
    {
        %s
    }

    public function previouslyRecordedAs(): array
    {
        return %s;
    }
};
PHP;

        file_put_contents(
            $this->directory.'/'.$name.'.php',
            sprintf($template, $up, $down, $previouslyRecordedAs)
        );
    }

    private function addMigrationWithoutRollback(string $name): void
    {
        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

return new class extends Migration
{
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
    }
};
PHP;

        file_put_contents($this->directory.'/'.$name.'.php', $template);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
