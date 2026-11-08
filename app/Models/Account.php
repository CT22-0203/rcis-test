<?php

namespace App\Models;

/**
 * Account — value object for a business account row.
 */
final class Account
{
    public function __construct(
        public readonly int    $id,
        public readonly string $ownerName,
        public readonly string $email,
        public readonly float  $balance,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** Hydrate from a database row. */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)    $row['id'],
            (string) $row['owner_name'],
            (string) $row['email'],
            (float)  $row['balance'],
            (string) $row['status'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
