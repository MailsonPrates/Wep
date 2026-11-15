<?php

use Wep\Router\Http\RouterHttpRequest;

/*
|--------------------------------------------------------------------------
| Helpers de HTTP: simulam uma requisição nos superglobais
|--------------------------------------------------------------------------
*/

/**
 * @param array{query?: array, post?: array, body?: string, headers?: array<string,string>, server?: array} $options
 */
function fakeRequest(string $method, string $route = '/', array $options = []): void
{
    static $original = null;
    $original ??= $_SERVER;

    $query = $options['query'] ?? [];
    $queryString = http_build_query($query);

    $_SERVER = ($options['server'] ?? []) + [
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI' => $route . ($queryString ? "?$queryString" : ''),
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'app.test',
    ] + array_intersect_key($original, array_flip(['argv', 'argc', 'PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME']));

    foreach ($options['headers'] ?? [] as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $key = in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_$key";
        $_SERVER[$key] = $value;
    }

    $_GET = $query + ['route' => $route];
    $_POST = $options['post'] ?? [];
    $_FILES = [];
    $_COOKIE = [];

    RouterHttpRequest::setRawBody($options['body'] ?? '');
}

function resetRequest(): void
{
    $_GET = $_POST = $_FILES = $_COOKIE = [];
    RouterHttpRequest::setRawBody(null);
    RouterHttpRequest::setTrustedProxies([]);
}

/** Executa o router e devolve o que foi impresso. */
function dispatch(): string
{
    ob_start();

    try {
        \Wep\Router\Route::execute();
    } finally {
        $output = ob_get_clean();
    }

    return $output;
}
