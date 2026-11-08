<?php

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $found);
        $params = $found[1];

        $pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $path);

        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            array_shift($matches);
            $params = array_combine($route['params'], $matches);

            [$class, $action] = $route['handler'];
            (new $class())->$action(...array_values($params));

            return;
        }

        http_response_code(404);
        View::render('errors/404', ['title' => 'Not Found']);
    }
}