<?php

namespace App\Controllers;

use App\Core\Controller;

/**
 * HomeController — landing page.
 * If logged in, redirect to /accounts; otherwise to /login.
 */
final class HomeController extends Controller
{
    public function index(): void
    {
        if (!empty($_SESSION['user'])) {
            $this->redirect(url('accounts'));
        }
        $this->redirect(url('login'));
    }
}
