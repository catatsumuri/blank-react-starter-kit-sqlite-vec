<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;
use Pdo\Sqlite as SqlitePdo;

class SqliteVecConnector extends SQLiteConnector
{
    /**
     * Create a PDO connection that preserves access to SQLite-specific APIs.
     *
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): PDO
    {
        return class_exists(SqlitePdo::class)
            ? new SqlitePdo($dsn, $username, $password, $options)
            : parent::createPdoConnection($dsn, $username, $password, $options);
    }
}
