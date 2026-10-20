<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator\Exception;

class InvalidMigrationException extends MigrationException
{
    public static function invalidMigrationFile(string $path, string $expectedClass): self
    {
        return new self(sprintf(
            "Migration file '%s' must return an instance of %s.",
            $path,
            $expectedClass
        ));
    }

    public static function invalidTrackerTable(string $table): self
    {
        return new self(sprintf(
            "Tracker table name '%s' must be a valid SQL identifier (letters, digits, and underscores only).",
            $table
        ));
    }
}
