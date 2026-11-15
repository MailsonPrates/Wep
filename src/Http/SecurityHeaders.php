<?php

namespace Wep\Http;

use Wep\Router\Http\RouterHttpRequest;

/**
 * Headers de segurança enviados em toda resposta HTTP.
 *
 * config/app.php:
 *   'security' => [
 *       'headers' => true,                     // false desliga todos
 *       'headers' => ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => null], // sobrescreve/remove
 *       'hsts' => false,                       // true ou ['max_age' => 31536000, 'include_subdomains' => false, 'preload' => false]
 *   ]
 */
final class SecurityHeaders
{
    public const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /**
     * @param array<string, mixed> $security config "security"
     * @return array<string, string> headers que devem ser enviados
     */
    public static function resolve(array $security, bool $isHttps): array
    {
        $option = $security['headers'] ?? true;

        if ($option === false) {
            return [];
        }

        $headers = self::DEFAULTS;

        if (is_array($option)) {
            foreach ($option as $name => $value) {
                if ($value === null || $value === false) {
                    unset($headers[$name]);
                } else {
                    $headers[$name] = (string) $value;
                }
            }
        }

        // HSTS só faz sentido (e só é aceito pelo navegador) em HTTPS
        $hsts = $security['hsts'] ?? false;

        if ($hsts && $isHttps) {
            $hsts = is_array($hsts) ? $hsts : [];
            $value = 'max-age=' . (int) ($hsts['max_age'] ?? 31536000);

            if (!empty($hsts['include_subdomains'])) $value .= '; includeSubDomains';
            if (!empty($hsts['preload'])) $value .= '; preload';

            $headers['Strict-Transport-Security'] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $security config "security"
     */
    public static function send(array $security): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }

        // não divulga a versão do PHP (expose_php)
        header_remove('X-Powered-By');

        foreach (self::resolve($security, RouterHttpRequest::isHttps()) as $name => $value) {
            header("$name: $value");
        }

        $csp = Csp::header($security);

        if ($csp) {
            Csp::activate();
            header($csp[0] . ': ' . $csp[1]);
        }
    }
}
