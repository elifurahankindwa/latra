<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;
use PDOStatement;

final class PdoParameterAliasRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function enabledAliases(): array
    {
        $statement = $this->database->prepare('SELECT * FROM param_aliases WHERE is_enabled = 1');
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
