<?php

namespace App\Repositories;

use App\Models\User;
use PDO;

/**
 * UserRepository — all SQL related to the `users` table.
 */
final class UserRepository
{
    /** Injecting PDO (rather than calling Database::connection() inside)
     *  keeps the class testable: a test can pass an in-memory SQLite PDO. */
    public function __construct(private PDO $pdo) {}

    /**
     * Find a user by login name.
     *
     * Uses a prepared statement (? placeholder) so a username like
     * `' OR 1=1 --` is treated as a literal string, not SQL. (This is the
     * one SQL-injection protection we keep because it's simply "correct SQL".)
     */
    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : User::fromRow($row);
    }
}
