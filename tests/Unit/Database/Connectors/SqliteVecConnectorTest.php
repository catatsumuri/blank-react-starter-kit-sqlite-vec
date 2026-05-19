<?php

namespace Tests\Unit\Database\Connectors;

use App\Database\Connectors\SqliteVecConnector;
use Pdo\Sqlite as SqlitePdo;
use PHPUnit\Framework\TestCase;

class SqliteVecConnectorTest extends TestCase
{
    public function test_it_creates_a_pdo_sqlite_connection()
    {
        $connector = new class extends SqliteVecConnector
        {
            public function createSqlitePdo(string $dsn): \PDO
            {
                return $this->createPdoConnection($dsn, null, null, []);
            }
        };

        $pdo = $connector->createSqlitePdo('sqlite::memory:');

        $this->assertInstanceOf(SqlitePdo::class, $pdo);
        $this->assertTrue(method_exists($pdo, 'loadExtension'));
    }
}
