<?php

declare(strict_types=1);

namespace Ttpryg\DatabaseMigrator\Exception;

use RuntimeException;

class MigrationException extends RuntimeException implements ExceptionInterface
{
    public static function rollbackNotSupported(string $migration): self
    {
        return new self(sprintf('%s does not support rollback.', $migration));
    }
}
