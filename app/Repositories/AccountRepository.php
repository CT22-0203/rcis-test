<?php

namespace App\Repositories;

use App\Models\Account;
use PDO;

/**
 * AccountRepository — all SQL related to the `accounts` table.
 */
final class AccountRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * All accounts, newest first.
     *
     * @return Account[]
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM accounts ORDER BY id DESC');
        // array_map applies Account::fromRow to each row, returning objects.
        return array_map([Account::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * Find by primary key. Returns null when not found.
     */
    public function find(int $id): ?Account
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : Account::fromRow($row);
    }

    /**
     * Insert a new account. Returns the generated id.
     *
     * @param array{owner_name:string,email:string,balance:float,status:string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (owner_name, email, balance, status)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['owner_name'],
            $data['email'],
            $data['balance'],
            $data['status'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing account. Returns true on success.
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE accounts
                SET owner_name = ?, email = ?, balance = ?, status = ?
              WHERE id = ?'
        );
        return $stmt->execute([
            $data['owner_name'],
            $data['email'],
            $data['balance'],
            $data['status'],
            $id,
        ]);
    }

    /**
     * Is this email already used by a *different* account?
     * Used by the service layer to give a friendly validation error instead
     * of letting the UNIQUE constraint throw.
     */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql  = 'SELECT COUNT(*) FROM accounts WHERE email = ?';
        $args = [$email];

        if ($exceptId !== null) {
            $sql   .= ' AND id <> ?';
            $args[] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn() > 0;
    }
}
