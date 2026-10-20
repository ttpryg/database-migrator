# Database Migrator (`ttpryg/database-migrator`)

A framework-agnostic PDO schema migration runner for PHP 8.1+ with migration
tracking and rollback. It has no framework dependencies and works with any
module that owns its own tables and migration files.

---

## ✨ Features

- **PDO Only**: Works with MySQL, MariaDB, SQLite, and any PDO driver.
- **Banker Tracked**: Records applied migrations in a tracker table so
  `migrate()` can be run repeatedly and safely.
- **Rollback Support**: Reverts the most recent migrations through each
  migration's `down()` method.
- **Configurable**: Migration directory and tracker table name are supplied by
  the caller, so multiple modules can share one database without collisions.
- **Baseline Friendly**: `markAllAsMigrated()` records migrations without
  executing them, useful when a schema snapshot already reflects the latest
  schema.
- **Framework Agnostic**: No Laravel, Symfony, or other framework dependency.

---

## 📦 Installation

```bash
composer require ttpryg/database-migrator
```

> **Requirements:** PHP 8.1+, `ext-pdo`, and a configured PDO connection.

---

## 🚀 Usage

### 1. Create a Migration

Each migration file must return an instance of `Migration`. Files are executed
in filename (alphabetical) order, so use a sortable prefix:

```php
<?php

// database/migrations/202610200001_create_audit_logs.php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

return new class extends Migration
{
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, event_name VARCHAR(100))');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE audit_logs');
    }
};
```

Migrations that cannot be reverted safely may omit `down()`. The default
implementation throws `MigrationException`, so `rollback()` fails loudly
instead of pretending the change was undone.

### 2. Run Migrations

```php
use Ttpryg\DatabaseMigrator\MigrationRunner;

$runner = new MigrationRunner(
    $pdo,
    __DIR__.'/database/migrations',
    'audit_engine_migrations'
);

$executed = $runner->migrate();

$applied = $runner->appliedMigrations();
```

### 3. Roll Back

```php
$rolledBack = $runner->rollback();    // revert the latest migration
$rolledBack = $runner->rollback(3);   // revert the latest three migrations
```

### 4. Baseline an Existing Schema

When a schema snapshot already reflects the latest schema, record the
migrations as applied without executing them:

```php
$runner->markAllAsMigrated();
```

### 5. Renamed Migrations

If a migration file was renamed in a deployed environment, declare the old
name so it is not executed twice:

```php
public function previouslyRecordedAs(): array
{
    return ['001_create_audit_logs'];
}
```

---

## 🗄️ Tracker Table

The runner creates the tracker table automatically:

```sql
CREATE TABLE IF NOT EXISTS <tracker_table> (
    migration VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NULL
);
```

Choose a module-specific tracker name (for example
`audit_engine_migrations`) when several modules share a database.

> **MySQL/MariaDB note:** DDL statements are auto-commit. A failure in the
> middle of a migration file cannot be rolled back automatically, so write
> migrations to be idempotent where practical (for example
> `CREATE TABLE IF NOT EXISTS`).

---

## 🧪 Development

```bash
composer install
composer test
composer analyse
composer fix:check
```

---

## 📄 License

MIT License.
