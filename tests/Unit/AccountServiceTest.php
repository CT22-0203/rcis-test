<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Services\AccountService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase
{
    private PDO $pdo;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE accounts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_name TEXT NOT NULL,
                email      TEXT NOT NULL UNIQUE,
                balance    NUMERIC NOT NULL DEFAULT 0,
                status     TEXT NOT NULL DEFAULT 'active',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        Database::setConnection($this->pdo);
        $this->service = new AccountService(new AccountRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        Database::reset();
    }

    public function test_create_persists_valid_account(): void
    {
        $errors = $this->service->create([
            'owner_name' => 'Jane Doe',
            'email'      => 'jane@example.com',
            'balance'    => '120.50',
            'status'     => 'active',
        ]);

        $this->assertSame([], $errors);

        $all = $this->service->all();
        $this->assertCount(1, $all);
        $this->assertSame('Jane Doe', $all[0]->ownerName);
        $this->assertSame(120.50, $all[0]->balance);
    }

    public function test_create_rejects_empty_owner(): void
    {
        $errors = $this->service->create([
            'owner_name' => '',
            'email'      => 'x@example.com',
            'balance'    => '0',
            'status'     => 'active',
        ]);
        $this->assertArrayHasKey('owner_name', $errors);
    }

    public function test_create_rejects_invalid_email(): void
    {
        $errors = $this->service->create([
            'owner_name' => 'Jane',
            'email'      => 'not-an-email',
            'balance'    => '0',
            'status'     => 'active',
        ]);
        $this->assertArrayHasKey('email', $errors);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $this->service->create([
            'owner_name' => 'A',
            'email'      => 'dup@example.com',
            'balance'    => '1',
            'status'     => 'active',
        ]);

        $errors = $this->service->create([
            'owner_name' => 'B',
            'email'      => 'dup@example.com',
            'balance'    => '2',
            'status'     => 'active',
        ]);
        $this->assertArrayHasKey('email', $errors);
    }

    public function test_create_rejects_invalid_status(): void
    {
        $errors = $this->service->create([
            'owner_name' => 'Jane',
            'email'      => 'j@example.com',
            'balance'    => '0',
            'status'     => 'banana',
        ]);
        $this->assertArrayHasKey('status', $errors);
    }

    public function test_update_changes_fields(): void
    {
        $this->service->create([
            'owner_name' => 'Old Name',
            'email'      => 'old@example.com',
            'balance'    => '10',
            'status'     => 'active',
        ]);

        $account = $this->service->all()[0];

        $errors = $this->service->update($account->id, [
            'owner_name' => 'New Name',
            'email'      => 'new@example.com',
            'balance'    => '99.99',
            'status'     => 'inactive',
        ]);

        $this->assertSame([], $errors);

        $updated = $this->service->find($account->id);
        $this->assertSame('New Name', $updated->ownerName);
        $this->assertSame('new@example.com', $updated->email);
        $this->assertSame(99.99, $updated->balance);
        $this->assertSame('inactive', $updated->status);
    }

    public function test_update_allows_keeping_own_email(): void
    {
        $this->service->create([
            'owner_name' => 'A',
            'email'      => 'same@example.com',
            'balance'    => '1',
            'status'     => 'active',
        ]);
        $id = $this->service->all()[0]->id;

        $errors = $this->service->update($id, [
            'owner_name' => 'A renamed',
            'email'      => 'same@example.com',   // same email as before
            'balance'    => '2',
            'status'     => 'active',
        ]);

        $this->assertSame([], $errors);
    }
}
