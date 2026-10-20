<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator\Exception;

class MigrationNotFoundException extends MigrationException
{
    public static function forRollback(string $migration): self
    {
        return new self(sprintf(
            "Cannot roll back migration '%s': migration file not found.",
            $migration
        ));
    }
}
