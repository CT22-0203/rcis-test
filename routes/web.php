<?php
/**
 * Route table.
 *
 * ORDER MATTERS: specific before generic. "/accounts/create" must be tested
 * before "/accounts/{id}" — otherwise "create" would be captured as {id}.
 */

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Core\Router;

/** @var Router $router */
$router->get('/', [HomeController::class, 'index']);

// Authentication
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// Accounts CRUD
$router->get('/accounts', [AccountController::class, 'index']);
$router->get('/accounts/create', [AccountController::class, 'create']);
$router->post('/accounts', [AccountController::class, 'store']);
$router->get('/accounts/{id}', [AccountController::class, 'show']);
$router->get('/accounts/{id}/edit', [AccountController::class, 'edit']);
$router->post('/accounts/{id}', [AccountController::class, 'update']);