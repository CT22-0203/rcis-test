<?php

namespace App\Core;

/**
 * Controller — base class for all controllers.
 *
 * Provides the two most common actions:
 *   - render a view (with optional data)
 *   - redirect (followed by exit so no further code runs)
 *   - guard a route behind authentication
 *
 * Subclasses call these via $this.
 */
abstract class Controller
{
    /**
     * Render a template.
     *
     * @param string $template path relative to app/Views (no extension)
     * @param array  $data     variables to expose to the template
     */
    protected function view(string $template, array $data = []): void
    {
        View::render($template, $data);
    }

    /**
     * Send a 302 redirect and stop.
     *
     * WHY EXIT: Without exit, PHP continues executing the current method
     * after setting the Location header. That often means doing work the
     * redirect was meant to skip (like rendering a "success" page that the
     * browser will never show). exit is the correct primitive.
     *
     * @SuppressWarnings(PHPMD.ExitExpression)
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Guard: redirect to /login unless the session has an authenticated user.
     * Call this from the constructor of any controller that must be protected.
     */
    protected function requireAuth(): void
    {
        if (empty($_SESSION['user'])) {
            $this->redirect(url('login'));
        }
    }
}
