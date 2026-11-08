<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AuthServiceTest — exercises login logic in isolation.
 * Uses in-memory SQLite so no MySQL server is needed.
 */
final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;

    protected function setUp(): void
    {
        // Fresh in-memory DB per test → total isolation.
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Compatible schema (SQLite dialect).
        $this->pdo->exec("
            CREATE TABLE users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL UNIQUE,
                password   TEXT NOT NULL,
                full_name  TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Seed the one user the test uses.
        $this->pdo->exec("
            INSERT INTO users (username, password, full_name)
            VALUES ('admin', 'admin123', 'Test Admin')
        ");

        // Wire up the service exactly as production does.
        Database::setConnection($this->pdo);
        $this->auth = new AuthService(new UserRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        // Clear singleton so other test cases start clean.
        Database::reset();
    }

    public function test_attempt_succeeds_with_correct_credentials(): void
    {
        $user = $this->auth->attempt('admin', 'admin123');

        $this->assertNotNull($user);
        $this->assertSame('admin', $user->username);
        $this->assertSame('Test Admin', $user->fullName);
    }

    public function test_attempt_fails_with_wrong_password(): void
    {
        $this->assertNull($this->auth->attempt('admin', 'nope'));
    }

    public function test_attempt_fails_with_unknown_user(): void
    {
        $this->assertNull($this->auth->attempt('ghost', 'whatever'));
    }

    public function test_attempt_trims_username_whitespace(): void
    {
        $user = $this->auth->attempt('  admin  ', 'admin123');
        $this->assertNotNull($user);
    }
}