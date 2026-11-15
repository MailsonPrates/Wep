<?php

namespace Wep\Http;

/**
 * Caminho da requisição atual, o mesmo para o roteador, a proteção CSRF e o ErrorHandler.
 *
 * Fonte: o caminho real da URL (REQUEST_URI), sem o diretório base do APP_URL. Funciona igual no
 * Apache (com ou sem o .htaccess), no Nginx e no `php -S`.
 *
 * `?route=` só vale quando a URL aponta para o próprio front controller (`/` ou `/index.php`), para
 * hospedagens sem reescrita de URL (`/index.php?route=/admin/`). Antes o roteador lia só o `?route=`:
 *  - sem a regra do .htaccess (Nginx, `php -S`, mod_rewrite desligado) toda URL virava "/" e as
 *    chamadas de API quebravam;
 *  - com a regra (`index.php?route=/$1 [QSA]`), `GET /login/?route=/admin/user/` executava
 *    /admin/user/ com a URL /login/ (o QSA junta o ?route= do cliente e o PHP fica com o último).
 */
final class RequestPath
{
    /** Front controllers que aceitam o ?route= de hospedagens sem reescrita. */
    private const FRONT_CONTROLLERS = ['/', '/index.php'];

    /**
     * @param array $server $_SERVER
     * @param array $query  $_GET
     * @param string $baseUrl APP_URL (o caminho dele, ex. "/app", é removido do início)
     */
    public static function resolve(array $server, array $query, string $baseUrl = ''): string
    {
        $uri = $server['REQUEST_URI'] ?? null;
        $route = $query['route'] ?? null;
        $route = is_string($route) && $route !== '' ? self::normalize($route) : null;

        // CLI/testes sem REQUEST_URI
        if ( !is_string($uri) || $uri === '' ) return $route ?? '/';

        // caminho = tudo antes de "?"/"#" (parse_url leria "//x/" como domínio "x")
        $path = self::normalize(rawurldecode(strtok(strtok($uri, '?'), '#') ?: '/'));
        $basePath = rtrim((string) (parse_url($baseUrl, PHP_URL_PATH) ?? ''), '/');

        if ( $basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/')) ){
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        if ( $route !== null && in_array($path, self::FRONT_CONTROLLERS, true) ) return $route;

        return $path;
    }

    /** Caminho da requisição atual (superglobais + APP_URL). */
    public static function current(): string
    {
        return self::resolve($_SERVER, $_GET, defined('APP_URL') ? (string) APP_URL : '');
    }

    private static function normalize(string $path): string
    {
        // sem bytes de controle; sempre começa com uma barra só
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

        return '/' . ltrim($path, '/');
    }
}
