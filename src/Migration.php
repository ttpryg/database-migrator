<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator;

use PDO;
use Ttpryg\DatabaseMigrator\Exception\MigrationException;

/**
 * Base class for PHP-based schema migrations.
 *
 * Each migration file must return an instance of this class, for example:
 *
 *     return new class extends Migration {
 *         public function up(PDO $pdo): void { ... }
 *         public function down(PDO $pdo): void { ... }
 *     };
 */
abstract class Migration
{
    /**
     * Apply the schema change.
     */
    abstract public function up(PDO $pdo): void;

    /**
     * Revert the schema change.
     *
     * Migrations that cannot be reverted safely (for example, migrations that
     * destroy data) should keep this default implementation so `rollback()`
     * fails loudly instead of pretending the change was undone.
     */
    public function down(PDO $pdo): void
    {
        throw MigrationException::rollbackNotSupported(static::class);
    }

    /**
     * Names this migration was recorded under before it was renamed.
     *
     * @return array<int, string>
     */
    public function previouslyRecordedAs(): array
    {
        return [];
    }
}
