<?php

namespace App\Core;

/**
 * Router — maps HTTP method + path to a controller action.
 *
 * Route paths support {param} placeholders, e.g.:
 *   GET /accounts/{id}/edit   →  AccountController@edit($id)
 *
 * The placeholder is compiled to a regex capture group at registration time.
 * On dispatch we try each route's regex against the request path. First match
 * wins — so order routes from most-specific to least-specific.
 */
final class Router
{
    /**
     * @var array<int, array{
     *   method: string,
     *   pattern: string,
     *   params: string[],
     *   handler: array{0:class-string,1:string}
     * }>
     */
    private array $routes = [];

    /** Register a GET route. */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** Register a POST route. */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * Compile and store a route.
     *
     * @param string $method  HTTP verb
     * @param string $path    e.g. "/accounts/{id}/edit"
     * @param array  $handler [ControllerClass::class, 'methodName']
     */
    private function add(string $method, string $path, array $handler): void
    {
        // Find every {name} placeholder and remember its name.
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $m);
        $params = $m[1]; // e.g. ['id']

        // Replace each placeholder with a capture group that matches anything
        // except "/". '#' delimiters avoid escaping "/" in patterns.
        $pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $path);

        $this->routes[] = [
            'method'  => $method,
            'pattern' => '#^' . $pattern . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Match and execute.
     *
     * @param string $method HTTP verb of the incoming request
     * @param string $uri    path only (query string already stripped)
     */
    public function dispatch(string $method, string $uri): void
    {
        // Normalise: strip trailing slash except for the root "/".
        $path = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue; // wrong verb, keep searching
            }
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue; // path doesn't match, keep searching
            }

            // $matches[0] is the full match; the rest are capture groups.
            array_shift($matches);

            // Combine capture values with placeholder names (id, slug, ...).
            $params = array_combine($route['params'], $matches);

            // Instantiate the controller and invoke the action with params
            // spread positionally: ->edit('5')
            [$class, $action] = $route['handler'];
            (new $class())->$action(...array_values($params));

            return;
        }

        // Nothing matched → 404.
        http_response_code(404);
        View::render('errors/404', ['title' => 'Not Found']);
    }
}