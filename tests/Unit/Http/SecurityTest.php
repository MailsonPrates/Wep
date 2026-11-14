<?php

use Wep\Http\HttpException;
use Wep\Http\OriginGuard;
use Wep\Http\SecurityHeaders;

function server(string $method, array $headers = []): array
{
    return $headers + ['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'app.test'];
}

describe('OriginGuard (CSRF)', function () {

    $app = 'https://app.test';

    it('métodos seguros passam sempre', function () use ($app) {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            expect(OriginGuard::check(server($method, ['HTTP_ORIGIN' => 'https://evil.test']), [], $app))->toBeNull();
        }
    });

    it('POST da própria origem passa (APP_URL ou Host da requisição)', function () use ($app) {
        expect(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'https://app.test']), [], $app))->toBeNull()
            ->and(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'https://APP.test:443']), [], $app))->toBeNull()
            ->and(OriginGuard::check(server('PUT', ['HTTP_ORIGIN' => 'http://app.test']), [], ''))->toBeNull();
    });

    it('POST, PUT, PATCH e DELETE de outra origem são bloqueados', function () use ($app) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            expect(OriginGuard::check(server($method, ['HTTP_ORIGIN' => 'https://evil.test']), [], $app))
                ->toContain('origem não permitida');
        }
    });

    it('não confunde subdomínio ou sufixo parecido com a própria origem', function () use ($app) {
        expect(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'https://app.test.evil.test']), [], $app))->not->toBeNull()
            ->and(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'https://evilapp.test']), [], $app))->not->toBeNull()
            ->and(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'https://app.test:8443']), [], $app))->not->toBeNull();
    });

    it('Origin "null" (iframe sandbox, file://) é bloqueado', function () use ($app) {
        expect(OriginGuard::check(server('POST', ['HTTP_ORIGIN' => 'null']), [], $app))->not->toBeNull();
    });

    it('sem Origin, bloqueia Sec-Fetch-Site: cross-site e aceita same-origin', function () use ($app) {
        expect(OriginGuard::check(server('POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site']), [], $app))->not->toBeNull()
            ->and(OriginGuard::check(server('POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin']), [], $app))->toBeNull();
    });

    it('sem nenhum header de origem (curl, webhook) passa', function () use ($app) {
        expect(OriginGuard::check(server('POST'), [], $app))->toBeNull();
    });

    it('trusted_origins, csrf_except e csrf=false', function () use ($app) {
        $evil = server('POST', ['HTTP_ORIGIN' => 'https://parceiro.test']);

        expect(OriginGuard::check($evil, ['trusted_origins' => ['https://parceiro.test/']], $app))->toBeNull()
            ->and(OriginGuard::check($evil, ['csrf_except' => ['/webhook/*']], $app, '/webhook/pagamento/'))->toBeNull()
            ->and(OriginGuard::check($evil, ['csrf_except' => ['/webhook/*']], $app, '/admin/user/save/'))->not->toBeNull()
            ->and(OriginGuard::check($evil, ['csrf' => false], $app))->toBeNull();
    });

    it('enforce() lança 403', function () use ($app) {
        OriginGuard::enforce(server('POST', ['HTTP_ORIGIN' => 'https://evil.test']), [], $app);
    })->throws(HttpException::class);
});

describe('SecurityHeaders', function () {

    it('padrão: nosniff, SAMEORIGIN e Referrer-Policy; sem HSTS', function () {
        expect(SecurityHeaders::resolve([], true))->toBe([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    });

    it('permite sobrescrever, remover e desligar', function () {
        $headers = SecurityHeaders::resolve(['headers' => ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => null, 'Permissions-Policy' => 'camera=()']], false);

        expect($headers)->toBe(['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Permissions-Policy' => 'camera=()'])
            ->and(SecurityHeaders::resolve(['headers' => false], true))->toBe([]);
    });

    it('HSTS só com opt-in e só em HTTPS', function () {
        expect(SecurityHeaders::resolve(['hsts' => true], true)['Strict-Transport-Security'])->toBe('max-age=31536000')
            ->and(SecurityHeaders::resolve(['hsts' => true], false))->not->toHaveKey('Strict-Transport-Security')
            ->and(SecurityHeaders::resolve(['hsts' => ['max_age' => 600, 'include_subdomains' => true]], true)['Strict-Transport-Security'])
            ->toBe('max-age=600; includeSubDomains');
    });
});
