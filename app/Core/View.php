<?php

namespace App\Core;

use RuntimeException;

/**
 * View — minimal template renderer.
 *
 * WHY A WRAPPER: Views are plain PHP files. Without a wrapper, controllers
 * would `require` templates directly — but then the variable scope of the
 * template is whatever happened to be in the controller. This class takes an
 * explicit $data array, `extract()`s it into the template's local scope, and
 * `require`s the file. Controllers can no longer accidentally leak variables,
 * and we get a single place to add shared data (e.g. $appName) later.
 */
final class View
{
    /**
     * Render a template. Path is relative to app/Views and uses no extension.
     *
     * @param string $template e.g. "accounts/index"
     * @param array  $data     key → value pairs exposed as local variables
     */
    public static function render(string $template, array $data = []): void
    {
        // Build the absolute file path. __DIR__ is app/Core, so we go up one
        // level to app/ then down into Views/.
        $file = __DIR__ . '/../Views/' . $template . '.php';

        // Fail fast: a typo in the template name should produce a clear error,
        // not a blank page.
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }

        // Extract keys of $data into local variables. EXTR_SKIP prevents
        // accidental overwrite of $file and $data themselves.
        extract($data, EXTR_SKIP);

        // Include the template. Any variable above is visible to it.
        require $file;
    }
}