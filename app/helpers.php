<?php

// ----------------------------------------------------------------------------
// Global helper functions. Autoloaded by Composer via the "files" section of
// composer.json, so they are available everywhere without explicit require.
// ----------------------------------------------------------------------------

/**
 * Build an absolute URL using APP_URL as the base.
 *
 * WHY: The project can live at the web root ("/") OR in a sub-folder
 * ("/account-management-system/public"). Hard-coding hrefs breaks the second
 * case. Anchoring every link to APP_URL keeps the app portable.
 *
 * @param string $path e.g. "accounts" or "/accounts/1/edit"
 * @return string fully-qualified URL
 */
function url(string $path = ''): string
{
    // rtrim strips trailing "/" from APP_URL so we don't get "//" joins
    $base = rtrim(getenv('APP_URL') ?: '', '/');
    
    // ltrim strips leading "/" from $path for the same reason
    return $base . '/' . ltrim($path, '/');
}

/**
 * HTML-escape a value before echoing it into a template.
 *
 * WHY: This is the ONLY security-adjacent measure we keep in this iteration.
 * It's also just correct HTML authoring: a name containing "<script>" must be
 * displayed literally, not interpreted. ENT_QUOTES escapes both ' and ", so
 * the function is safe inside single- or double-quoted attributes.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Read and pop a one-time flash message from the session.
 *
 * WHY: After a redirect (POST → 302 → GET), the user needs feedback
 * ("Account created"). The POST handler stashes a message in the session; the
 * GET handler reads and removes it, so it displays exactly once.
 *
 * @return array{type:string,message:string}|null
 */
function flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $message;
}

/**
 * Store a flash message for the next request.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Read-and-pop validation errors stored by the previous request.
 */
function old_errors(): array
{
    $errors = $_SESSION['errors'] ?? [];
    unset($_SESSION['errors']);
    return $errors;
}

/**
 * Read-and-pop previously submitted form values, so forms can re-fill after
 * a failed validation instead of frustrating the user with a blank form.
 */
function old_input(): array
{
    $input = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    return $input;
}