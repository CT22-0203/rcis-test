<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;

/**
 * AuthService — decides whether supplied credentials are valid.
 *
 * NOTE ON SECURITY: This iteration compares plaintext passwords with "===".
 * That is intentional for the tutorial. The security iteration replaces this
 * with password_verify() against a bcrypt hash.
 */
final class AuthService
{
    public function __construct(private UserRepository $users) {}

    /**
     * @return User|null the authenticated user, or null on failure
     */
    public function attempt(string $username, string $password): ?User
    {
        // Trim only the username: users' passwords may legitimately have
        // leading/trailing whitespace, usernames never do.
        $user = $this->users->findByUsername(trim($username));

        if ($user === null) {
            return null; // unknown user
        }

        // Plaintext compare — TEMPORARY, see class docblock.
        if ($user->password !== $password) {
            return null; // wrong password
        }

        return $user;
    }
}