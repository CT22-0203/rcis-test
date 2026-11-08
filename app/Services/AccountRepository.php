<?php

namespace App\Services;

use App\Models\Account;
use App\Repositories\AccountRepository;

/**
 * AccountService — business rules for creating/updating accounts.
 *
 * Returns a `$errors` array (empty = success). That shape makes both the
 * controller (render errors next to fields) and the tests (assertSame([]))
 * trivial to write.
 */
final class AccountService
{
    /** Allowed statuses — mirrors the DB ENUM. */
    private const STATUSES = ['active', 'inactive', 'suspended'];

    public function __construct(private AccountRepository $accounts) {}

    /** @return Account[] */
    public function all(): array
    {
        return $this->accounts->all();
    }

    public function find(int $id): ?Account
    {
        return $this->accounts->find($id);
    }

    /**
     * Create an account from raw input.
     *
     * @param array<string,mixed> $input usually $_POST
     * @return array<string,string> validation errors, empty on success
     */
    public function create(array $input): array
    {
        $data   = $this->normalize($input);
        $errors = $this->validate($data);

        if ($errors) {
            return $errors;
        }

        $this->accounts->create($data);
        return [];
    }

    /**
     * Update an account from raw input.
     *
     * @return array<string,string> validation errors, empty on success
     */
    public function update(int $id, array $input): array
    {
        $data   = $this->normalize($input);
        $errors = $this->validate($data, $id);

        if ($errors) {
            return $errors;
        }

        $this->accounts->update($id, $data);
        return [];
    }

    /**
     * Coerce raw input into the exact shape the repository expects.
     * This is where "the string '5' from a form" becomes a float 5.0.
     */
    private function normalize(array $input): array
    {
        return [
            'owner_name' => trim((string) ($input['owner_name'] ?? '')),
            'email'      => trim((string) ($input['email'] ?? '')),
            'balance'    => (float) ($input['balance'] ?? 0),
            'status'     => (string) ($input['status'] ?? ''),
        ];
    }

    /**
     * Validate normalized data. Returns field → message.
     *
     * @param int|null $exceptId ignore this id in the email-uniqueness check
     *                           (used by update so a record can keep its email)
     */
    private function validate(array $data, ?int $exceptId = null): array
    {
        $errors = [];

        if ($data['owner_name'] === '') {
            $errors['owner_name'] = 'Owner name is required.';
        } elseif (mb_strlen($data['owner_name']) > 100) {
            $errors['owner_name'] = 'Owner name cannot exceed 100 characters.';
        }

        if ($data['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->accounts->emailExists($data['email'], $exceptId)) {
            $errors['email'] = 'This email is already used by another account.';
        }

        if (!is_numeric((string) ($data['balance'] ?? ''))) {
            $errors['balance'] = 'Balance must be numeric.';
        } elseif ($data['balance'] < 0) {
            $errors['balance'] = 'Balance cannot be negative.';
        }

        if (!in_array($data['status'], self::STATUSES, true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        return $errors;
    }
}