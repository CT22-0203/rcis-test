<?php

namespace App\Models;

/**
 * User — value object representing an authenticated operator.
 *
 * Immutability (readonly properties) protects invariants: once a User is
 * constructed, its id/username cannot change mid-request. Any "update" goes
 * through the repository and produces a new instance.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $password,   // TEMP: plaintext in this iteration
        public readonly string $fullName,
        public readonly string $createdAt,
    ) {}

    /**
     * Hydrate from a database row.
     *
     * WHY: Repositories fetch associative arrays; controllers/views want
     * typed objects. This is the single boundary where the shift happens.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)    $row['id'],           // PDO returns strings; cast for type safety
            (string) $row['username'],
            (string) $row['password'],
            (string) $row['full_name'],
            (string) $row['created_at'],
        );
    }
}