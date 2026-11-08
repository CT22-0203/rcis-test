<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * AuthController — handles GET /login (form) and POST /login (submit).
 */
final class AuthController extends Controller
{
    private AuthService $auth;

    /**
     * Constructor wires dependencies. Every request builds a fresh controller
     * instance, so we never worry about stale state between requests.
     */
    public function __construct()
    {
        $this->auth = new AuthService(new UserRepository(Database::connection()));
    }

    /** Show the login form. */
    public function showLogin(): void
    {
        $this->view('auth/login', ['title' => 'Sign In']);
    }

    /** Handle login form submission. */
    public function login(): void
    {
        // $_POST values may be missing entirely — default to ''.
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $user = $this->auth->attempt($username, $password);

        if ($user === null) {
            // Store an error message and bounce back to the form.
            set_flash('error', 'Invalid username or password.');
            $this->redirect(url('login'));
        }

        // Persist just the fields we need in the session. Do NOT store the
        // password (even a hash) — session storage may be logged or leaked.
        $_SESSION['user'] = [
            'id'        => $user->id,
            'username'  => $user->username,
            'full_name' => $user->fullName,
        ];

        $this->redirect(url('accounts'));
    }

    /** Log out and redirect to login. */
    public function logout(): void
    {
        // Only unset the auth key, not the whole session — preserves flashes.
        unset($_SESSION['user']);
        set_flash('success', 'You have been signed out.');
        $this->redirect(url('login'));
    }
}
