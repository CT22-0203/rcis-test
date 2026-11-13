<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Database — PDO connection holder (Singleton pattern).
 *
 * WHY SINGLETON: Opening a new PDO connection per query is wasteful (each
 * handshake is a TCP round-trip). One connection per HTTP request, reused by
 * every repository, is the standard pattern. The static holder lets any
 * repository grab the same PDO without threading it through dozens of
 * constructors.
 *
 * TESTABILITY: setConnection() lets PHPUnit inject an in-memory SQLite
 * connection, so unit tests don't need a running MySQL server.
 */
final class Database
{
    /** The single PDO instance for this request (null until first use). */
    private static ?PDO $instance = null;

    /** Private constructor: "new Database()" must never be allowed. */
    private function __construct() {}

    /** Private clone: prevents "$db2 = clone $db1" duplicating state. */
    private function __clone() {}

    /**
     * Return the shared PDO connection, creating it on first call.
     *
     * @throws PDOException if the connection fails.
     */
    public static function connection(): PDO
    {
        // Only connect once per process.
        if (self::$instance === null) {
            // Load config fresh. Cheap (array + file_exists cache).
            $config = require __DIR__ . '/../../config/config.php';
            $db     = $config['db'];

            // DSN = Data Source Name. "mysql:" prefix selects the PDO driver.
            // Prepared statements work the same across MySQL/Postgres/SQLite.
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            );

            try {
                // Third argument = driver options:
                //   ERRMODE_EXCEPTION   — throw on error instead of silent false
                //   DEFAULT_FETCH_MODE  — SELECT returns associative arrays by default
                //   EMULATE_PREPARES=false — use real server-side prepared statements,
                //                            which enforce correct typing of bound params
                self::$instance = new PDO(
                    $dsn,
                    $db['username'],
                    $db['password'],
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                // Wrap with a generic message. Detailed PDO messages can leak
                // credentials in stack traces if displayed to end users.
                throw new PDOException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Replace the shared connection. Used exclusively by tests.
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    /**
     * Drop the shared connection. Used by tests between cases.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}