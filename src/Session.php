<?php

namespace Wep;

use Wep\Router\Http\RouterHttpRequest;

/**
 * Sessão PHP com configuração segura por padrão.
 *
 * - use_strict_mode: o PHP recusa IDs de sessão que ele não gerou (bloqueia session fixation
 *   com ID escolhido pelo atacante);
 * - cookie HttpOnly, SameSite=Lax e Secure em HTTPS;
 * - expiração por inatividade (idle_timeout, padrão 2h);
 * - regenerate() troca o ID (chame no login e em qualquer mudança de privilégio).
 *
 * config/app.php (todas opcionais):
 *   'session' => [
 *       'name' => 'PHPSESSID',
 *       'idle_timeout' => 7200,   // segundos sem atividade; 0 desliga
 *       'lifetime' => 0,          // validade do cookie em segundos; 0 = até fechar o navegador
 *       'samesite' => 'Lax',      // Lax | Strict | None (None exige HTTPS)
 *       'secure' => null,         // null = automático (só em HTTPS); true/false força
 *   ]
 */
class Session
{
    public const DEFAULT_IDLE_TIMEOUT = 7200;

    private const LAST_ACTIVITY = '__wep_last_activity';

    /** @var array<string, mixed> */
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Parâmetros do cookie de sessão.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public static function cookieParams(?bool $isHttps = null): array
    {
        $isHttps ??= RouterHttpRequest::isHttps();
        $sameSite = ucfirst(strtolower((string) (self::$config['samesite'] ?? 'Lax')));

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        $secure = self::$config['secure'] ?? null;
        $secure = $secure === null ? $isHttps : (bool) $secure;

        // navegadores recusam SameSite=None sem Secure
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => max(0, (int) (self::$config['lifetime'] ?? 0)),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    public static function idleTimeout(): int
    {
        return max(0, (int) (self::$config['idle_timeout'] ?? self::DEFAULT_IDLE_TIMEOUT));
    }

    /**
     * Inicializa a sessão (se ainda não foi) e aplica a expiração por inatividade.
     */
    public static function start()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) {
                return;
            }

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');

            if (!empty(self::$config['name']) && preg_match('/^[A-Za-z0-9]+$/', self::$config['name'])) {
                session_name(self::$config['name']);
            }

            session_set_cookie_params(self::cookieParams());
            session_start();
        }

        self::applyIdleTimeout();
    }

    /**
     * Troca o ID da sessão mantendo os dados (o ID antigo deixa de valer).
     */
    public static function regenerate(): void
    {
        self::start();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Add dados ao $_SESSION
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Pega valor salvo em uma sessão
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key = "", $default = "")
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Encerra a sessão: apaga os dados, o arquivo da sessão e o cookie.
     */
    public static function end()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();

            if (!headers_sent()) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]);
            }

            session_destroy();
        }
    }

    /**
     * Sessão expirada por inatividade? (lógica isolada para teste)
     */
    public static function isIdleExpired(?int $lastActivity, int $now, int $timeout): bool
    {
        return $timeout > 0 && $lastActivity !== null && ($now - $lastActivity) > $timeout;
    }

    private static function applyIdleTimeout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $now = time();
        $last = isset($_SESSION[self::LAST_ACTIVITY]) ? (int) $_SESSION[self::LAST_ACTIVITY] : null;

        if (self::isIdleExpired($last, $now, self::idleTimeout())) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION[self::LAST_ACTIVITY] = $now;
    }
}
