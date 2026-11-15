<?php

namespace Wep\Http;

/**
 * Proteção contra CSRF por verificação de origem (sem token), como recomenda a OWASP:
 * https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * Para métodos que alteram estado (POST, PUT, PATCH, DELETE):
 *  1. com header Origin: precisa ser a origem do próprio site (APP_URL ou Host da requisição)
 *     ou estar em "trusted_origins";
 *  2. sem Origin, com Sec-Fetch-Site: "cross-site" é bloqueado;
 *  3. sem nenhum dos dois (curl, webhooks, servidor-a-servidor): passa. Esses clientes não
 *     carregam o cookie de sessão do usuário, que é o que o CSRF explora.
 *
 * config/app.php:
 *   'security' => [
 *       'csrf' => true,
 *       'trusted_origins' => ['https://admin.exemplo.com'],
 *       'csrf_except' => ['/webhook/*'],   // caminhos ignorados (* = qualquer sequência)
 *   ]
 */
final class OriginGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $security config "security"
     *
     * @return string|null motivo do bloqueio, ou null se a requisição pode seguir
     */
    public static function check(array $server, array $security, string $appUrl = '', string $path = '/'): ?string
    {
        if (($security['csrf'] ?? true) === false) {
            return null;
        }

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');

        if (in_array($method, self::SAFE_METHODS, true)) {
            return null;
        }

        foreach ((array) ($security['csrf_except'] ?? []) as $pattern) {
            $regex = '~^' . str_replace('\*', '.*', preg_quote(rtrim($pattern, '/'), '~')) . '/?$~';

            if (preg_match($regex, rtrim($path, '/') . '/') || preg_match($regex, $path)) {
                return null;
            }
        }

        $origin = $server['HTTP_ORIGIN'] ?? '';

        if ($origin !== '') {
            if (self::isAllowedOrigin($origin, $server, $security, $appUrl)) {
                return null;
            }

            return "origem não permitida: $origin";
        }

        if (strtolower($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            return 'requisição de outro site (Sec-Fetch-Site: cross-site)';
        }

        return null;
    }

    /**
     * Executa a verificação e lança 403 quando a requisição for bloqueada.
     *
     * @throws HttpException
     */
    public static function enforce(array $server, array $security, string $appUrl = '', string $path = '/'): void
    {
        $reason = self::check($server, $security, $appUrl, $path);

        if ($reason !== null) {
            throw new HttpException(403, "Requisição bloqueada pela proteção CSRF: $reason");
        }
    }

    private static function isAllowedOrigin(string $origin, array $server, array $security, string $appUrl): bool
    {
        // "null": documentos sandboxed, file://, alguns redirects. Nunca é a própria aplicação.
        if (strtolower($origin) === 'null') {
            return false;
        }

        $normalized = self::normalize($origin);

        if ($normalized === null) {
            return false;
        }

        $allowed = array_map([self::class, 'normalize'], (array) ($security['trusted_origins'] ?? []));

        if ($appUrl !== '') {
            $allowed[] = self::normalize($appUrl);
        }

        if (in_array($normalized, array_filter($allowed), true)) {
            return true;
        }

        // mesmo host da requisição (ex.: APP_URL não configurado, ou proxy com TLS terminado):
        // compara host:porta, sem o esquema
        $host = strtolower($server['HTTP_HOST'] ?? '');

        return $host !== '' && self::hostPort($normalized) === self::stripDefaultPort($host);
    }

    /** "https://Exemplo.com:443/" → "https://exemplo.com" */
    private static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ($port ? ":$port" : '');
    }

    private static function hostPort(string $normalizedOrigin): string
    {
        return substr($normalizedOrigin, strpos($normalizedOrigin, '://') + 3);
    }

    private static function stripDefaultPort(string $host): string
    {
        return preg_replace('/:(80|443)$/', '', $host);
    }
}
