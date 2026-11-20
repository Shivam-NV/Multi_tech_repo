<?php
declare(strict_types=1);

namespace MatchApi;

use PDO;
use RuntimeException;

final class Database
{
    private ?PDO $connection = null;

    public function pdo(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '1433';
        $name = getenv('DB_NAME') ?: 'MatchApi';
        $encrypt = getenv('DB_ENCRYPT') ?: 'yes';
        $trust = getenv('DB_TRUST_SERVER_CERTIFICATE') ?: 'no';
        $dsn = sprintf(
            'sqlsrv:Server=%s,%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
            $host,
            $port,
            $name,
            $encrypt,
            $trust
        );

        try {
            $this->connection = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $exception) {
            throw new RuntimeException('Unable to connect to SQL Server. Check DB_* settings and the pdo_sqlsrv extension.', 0, $exception);
        }
        return $this->connection;
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
