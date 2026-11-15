<?php

namespace Wep\Http;

/**
 * Content-Security-Policy com nonce por requisição.
 *
 * Com a CSP ligada, o navegador só executa scripts do próprio site e scripts inline que tragam o
 * nonce da requisição. Mesmo que um XSS consiga injetar um <script>, ele não roda.
 * O Wep\View\Template\Html adiciona o nonce automaticamente em todo <script> que ele monta.
 *
 * config/app.php (desligada por padrão):
 *   'security' => [
 *       'csp' => true,                                         // política padrão
 *       'csp' => [                                             // ou ajustando diretivas
 *           'style-src' => ['https://fonts.googleapis.com'],   // fontes somadas ao padrão
 *           'font-src' => ['https://fonts.gstatic.com'],
 *           'script-src' => ['https://www.googletagmanager.com'],
 *           'report_only' => true,                             // só reporta (não bloqueia): bom para testar
 *       ],
 *   ]
 */
final class Csp
{
    public const DEFAULT_POLICY = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", '{nonce}'],
        'style-src' => ["'self'", "'unsafe-inline'"],   // estilos inline do React/bootstrap
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];

    private static ?string $nonce = null;

    private static bool $active = false;

    /**
     * Nonce desta requisição (o mesmo em todos os scripts da página).
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * A CSP está valendo nesta resposta? (o Html usa para colocar o nonce nos scripts)
     */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * @param array<string, mixed> $security config "security"
     */
    public static function isEnabled(array $security): bool
    {
        $csp = $security['csp'] ?? false;

        return $csp === true || (is_array($csp) && ($csp['enabled'] ?? true) !== false);
    }

    /**
     * Header a enviar, ou null com a CSP desligada.
     *
     * @return array{0: string, 1: string}|null [nome, valor]
     */
    public static function header(array $security): ?array
    {
        if (!self::isEnabled($security)) {
            return null;
        }

        $config = is_array($security['csp']) ? $security['csp'] : [];
        $policy = self::DEFAULT_POLICY;

        foreach ($config as $directive => $sources) {
            if (!preg_match('/^[a-z-]+$/', (string) $directive) || !is_array($sources)) {
                continue;
            }

            $policy[$directive] = array_values(array_unique(array_merge($policy[$directive] ?? [], $sources)));
        }

        $parts = [];

        foreach ($policy as $directive => $sources) {
            $sources = array_map(
                fn ($s) => $s === '{nonce}' ? "'nonce-" . self::nonce() . "'" : self::source((string) $s),
                $sources
            );

            $parts[] = trim($directive . ' ' . implode(' ', array_filter($sources)));
        }

        $name = !empty($config['report_only']) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

        return [$name, implode('; ', $parts)];
    }

    /**
     * Ativa a CSP para esta resposta (chamado pelo SecurityHeaders).
     */
    public static function activate(): void
    {
        self::$active = true;
    }

    /**
     * @internal testes: novo nonce e CSP inativa.
     */
    public static function reset(): void
    {
        self::$nonce = null;
        self::$active = false;
    }

    /**
     * Fonte da diretiva sem caracteres que quebrariam o header (; , quebra de linha).
     */
    private static function source(string $source): string
    {
        return preg_replace('/[;,\r\n\s]/', '', $source);
    }
}
