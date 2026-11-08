<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Services\AccountService;

/**
 * AccountController — CRUD for accounts.
 * Every action requires authentication; enforced in the constructor.
 */
final class AccountController extends Controller
{
    private AccountService $accounts;

    public function __construct()
    {
        // Guard first: an unauthenticated request never reaches a service.
        $this->requireAuth();

        $this->accounts = new AccountService(
            new AccountRepository(Database::connection())
        );
    }

    /** GET /accounts — list page. */
    public function index(): void
    {
        $this->view('accounts/index', [
            'title'    => 'Accounts',
            'accounts' => $this->accounts->all(),
        ]);
    }

    /** GET /accounts/create — show empty form. */
    public function create(): void
    {
        $this->view('accounts/create', [
            'title' => 'New Account',
            'old'   => old_input(),          // re-fill after failed validation
            'errors'=> old_errors(),
        ]);
    }

    /** POST /accounts — handle creation. */
    public function store(): void
    {
        $errors = $this->accounts->create($_POST);

        if ($errors) {
            // Re-render the form with errors and previous values.
            $_SESSION['errors'] = $errors;
            $_SESSION['old']    = $_POST;
            $this->redirect(url('accounts/create'));
        }

        set_flash('success', 'Account created successfully.');
        $this->redirect(url('accounts'));
    }

    /** GET /accounts/{id} — detail page. */
    public function show(string $id): void
    {
        $account = $this->accounts->find((int) $id);

        if ($account === null) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Account Not Found']);
            return;
        }

        $this->view('accounts/show', [
            'title'   => 'Account #' . $account->id,
            'account' => $account,
        ]);
    }

    /** GET /accounts/{id}/edit — pre-filled form. */
    public function edit(string $id): void
    {
        $account = $this->accounts->find((int) $id);

        if ($account === null) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Account Not Found']);
            return;
        }

        $this->view('accounts/edit', [
            'title'   => 'Edit Account #' . $account->id,
            'account' => $account,
            'old'     => old_input(),
            'errors'  => old_errors(),
        ]);
    }

    /** POST /accounts/{id} — handle update. */
    public function update(string $id): void
    {
        $accountId = (int) $id;
        $errors    = $this->accounts->update($accountId, $_POST);

        if ($errors) {
            $_SESSION['errors'] = $errors;
            $_SESSION['old']    = $_POST;
            $this->redirect(url('accounts/' . $accountId . '/edit'));
        }

        set_flash('success', 'Account updated successfully.');
        $this->redirect(url('accounts/' . $accountId));
    }
}
