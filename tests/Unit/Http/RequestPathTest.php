<?php

use Wep\Http\RequestPath;

describe('RequestPath', function () {

    it('usa o caminho real da URL (Nginx, php -S, Apache sem a regra do .htaccess)', function () {
        expect(RequestPath::resolve(['REQUEST_URI' => '/admin/user/?page=2'], ['page' => '2']))->toBe('/admin/user/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '/admin/user/api/get/'], []))->toBe('/admin/user/api/get/');
    });

    it('?route= do cliente não troca a rota de uma URL que já tem caminho (antes o QSA deixava)', function () {
        // Apache: index.php?route=/login/&route=/admin/user/ → o PHP fica com o último
        expect(RequestPath::resolve(['REQUEST_URI' => '/login/?route=/admin/user/'], ['route' => '/admin/user/']))->toBe('/login/');
    });

    it('?route= vale no front controller (hospedagem sem reescrita de URL)', function () {
        expect(RequestPath::resolve(['REQUEST_URI' => '/index.php?route=/admin/'], ['route' => '/admin/']))->toBe('/admin/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '/?route=/admin/'], ['route' => '/admin/']))->toBe('/admin/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '/'], []))->toBe('/');
    });

    it('remove o diretório base do APP_URL', function () {
        $server = ['REQUEST_URI' => '/sistema/admin/user/'];

        expect(RequestPath::resolve($server, [], 'https://exemplo.com/sistema'))->toBe('/admin/user/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '/sistema'], [], 'https://exemplo.com/sistema/'))->toBe('/')
            // "/sistemaX" não é o diretório "/sistema"
            ->and(RequestPath::resolve(['REQUEST_URI' => '/sistemax/a/'], [], 'https://exemplo.com/sistema'))->toBe('/sistemax/a/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '/sistema/index.php?route=/a/'], ['route' => '/a/'], 'https://exemplo.com/sistema'))->toBe('/a/');
    });

    it('decodifica a URL, remove bytes de controle e garante a barra inicial', function () {
        expect(RequestPath::resolve(['REQUEST_URI' => '/pedido/o%27brien/'], []))->toBe("/pedido/o'brien/")
            ->and(RequestPath::resolve(['REQUEST_URI' => "/a%00b/"], []))->toBe('/ab/')
            ->and(RequestPath::resolve(['REQUEST_URI' => '//x//'], []))->toBe('/x//');
    });

    it('sem REQUEST_URI (CLI): usa o ?route= ou "/"', function () {
        expect(RequestPath::resolve([], ['route' => '/a/']))->toBe('/a/')
            ->and(RequestPath::resolve([], []))->toBe('/')
            ->and(RequestPath::resolve([], ['route' => ['x']]))->toBe('/');
    });
});
