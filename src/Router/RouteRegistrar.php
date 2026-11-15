<?php

namespace Wep\Router;

/**
 * Registra as rotas a partir do build (storage/builds/route-maps.build.php), que agora é só dados:
 *
 *   ['middlewares' => [alias => classe], 'routes' => [[method, path, controller, middlewares]], 'fallback' => 'Classe@metodo']
 *
 * Antes o build era código PHP montado por concatenação (Route::get('$path', ...)): um apóstrofo
 * num path derrubava o site inteiro com erro de sintaxe.
 */
final class RouteRegistrar
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options'];

    public static function register(array $map): void
    {
        Route::globalMiddlewares((array) ($map['middlewares'] ?? []));

        foreach ((array) ($map['routes'] ?? []) as $route) {
            [$method, $path, $controller, $middlewares] = array_pad(array_values((array) $route), 4, []);

            $method = strtolower((string) $method);

            if (!in_array($method, self::METHODS, true)) {
                throw new \UnexpectedValueException("Método HTTP inválido no build de rotas: $method");
            }

            $registered = Route::{$method}((string) $path, (string) $controller);

            if ($middlewares) {
                $registered->middleware(array_values((array) $middlewares));
            }
        }

        if (!empty($map['fallback'])) {
            [$class, $action] = explode('@', (string) $map['fallback']) + [1 => 'handle'];

            Route::fallback(fn ($request) => (new $class())->{$action}($request));
        }
    }
}
