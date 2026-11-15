<?php

use Wep\Router\Route;

/**
 * O roteador executa a rota do caminho real da URL. Antes lia só o ?route= que o .htaccess acrescenta:
 * sem ele toda URL virava "/", e com ele o cliente trocava a rota pela query string.
 */
function routeByUrl(string $method, string $uri, array $get, string $baseUrl = 'http://app.test'): string
{
    fakeRequest($method, parse_url($uri, PHP_URL_PATH));
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $get;

    Route::start($baseUrl, '@');
    Route::get('/', fn () => print('home'));
    Route::get('/login', fn () => print('login'));
    Route::get('/admin/user', fn () => print('usuarios'));
    Route::post('/admin/user/api/get', fn () => print('api'));

    return dispatch();
}

afterEach(fn () => resetRequest());

describe('roteador pelo caminho real', function () {

    it('sem ?route= (Nginx, php -S, sem mod_rewrite): páginas e APIs funcionam', function () {
        expect(routeByUrl('GET', '/admin/user/', []))->toBe('usuarios')
            ->and(routeByUrl('POST', '/admin/user/api/get/', []))->toBe('api');
    });

    it('com a regra do .htaccess (?route= igual ao caminho): continua igual', function () {
        expect(routeByUrl('GET', '/admin/user/', ['route' => '/admin/user/']))->toBe('usuarios');
    });

    it('?route= do cliente não troca a rota (antes executava /admin/user/ com a URL /login/)', function () {
        expect(routeByUrl('GET', '/login/?route=/admin/user/', ['route' => '/admin/user/']))->toBe('login');
    });

    it('hospedagem sem reescrita: /index.php?route=/admin/user/', function () {
        expect(routeByUrl('GET', '/index.php?route=/admin/user/', ['route' => '/admin/user/']))->toBe('usuarios');
    });

    it('app num subdiretório (APP_URL com caminho)', function () {
        expect(routeByUrl('GET', '/sistema/admin/user/', [], 'http://app.test/sistema'))->toBe('usuarios')
            ->and(routeByUrl('GET', '/sistema/', [], 'http://app.test/sistema'))->toBe('home');
    });
});
