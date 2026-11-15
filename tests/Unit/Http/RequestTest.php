<?php

use Wep\Router\Http\RouterHttpRequest;

afterEach(fn () => resetRequest());

function request(string $method, array $options = [], array $params = []): RouterHttpRequest
{
    fakeRequest($method, '/user/{id}/', $options);

    return new RouterHttpRequest('http://app.test', '/user/{id}/', $params);
}

describe('corpo da requisição', function () {

    it('PUT com JSON (como o front envia o update; antes chegava corrompido)', function () {
        $r = request('PUT', ['body' => '{"fields":{"name":"x"},"where":["id",1]}', 'headers' => ['Content-Type' => 'application/json']]);

        expect($r->all())->toBe(['fields' => ['name' => 'x'], 'where' => ['id', 1]]);
    });

    it('DELETE e PATCH com JSON', function () {
        expect(request('DELETE', ['body' => '{"where":["id",1]}', 'headers' => ['Content-Type' => 'application/json']])->all())
            ->toBe(['where' => ['id', 1]])
            ->and(request('PATCH', ['body' => '{"a":1}', 'headers' => ['Content-Type' => 'application/json; charset=utf-8']])->all())
            ->toBe(['a' => 1]);
    });

    it('JSON sem Content-Type é reconhecido pelo formato', function () {
        expect(request('PUT', ['body' => '[1,2]'])->all())->toBe([1, 2]);
    });

    it('JSON inválido com Content-Type JSON vira corpo vazio', function () {
        expect(request('POST', ['body' => '{quebrado', 'headers' => ['Content-Type' => 'application/json']])->all())->toBe([]);
    });

    it('PUT com form-urlencoded', function () {
        $r = request('PUT', ['body' => 'name=Ana&age=30', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']]);

        expect($r->all())->toBe(['name' => 'Ana', 'age' => '30']);
    });

    it('POST de formulário usa $_POST', function () {
        expect(request('POST', ['post' => ['name' => 'Ana']])->all())->toBe(['name' => 'Ana']);
    });

    it('form spoofing com _method (inclusive minúsculo)', function () {
        $r = request('POST', ['post' => ['_method' => 'put', 'name' => 'Ana']]);

        expect($r->getMethod())->toBe('PUT')
            ->and($r->all())->toBe(['name' => 'Ana']);
    });

    it('GET não lê o corpo', function () {
        expect(request('GET', ['body' => '{"a":1}'])->all())->toBe([]);
    });
});

describe('precedência dos dados', function () {

    it('query string não sobrescreve corpo nem parâmetro da rota (parameter pollution)', function () {
        $r = request('POST', [
            'post' => ['role' => 'user', 'user_id' => '5'],
            'query' => ['role' => 'admin', 'id' => '999', 'page' => '2'],
        ], ['id' => '42']);

        expect($r->data('role'))->toBe('user')
            ->and($r->data('id'))->toBe('42')
            ->and($r->data('page'))->toBe('2')
            ->and($r->role)->toBe('user')
            ->and($r->id)->toBe('42');
    });

    it('parâmetro da rota vence o corpo', function () {
        expect(request('PUT', ['body' => '{"id":"1"}'], ['id' => '42'])->data('id'))->toBe('42');
    });

    it('propriedade inexistente é null (antes devolvia o "custom" inteiro da rota)', function () {
        $r = request('POST', ['post' => ['a' => '1']]);

        expect($r->naoExiste)->toBeNull()
            ->and($r->keep ?? false)->toBeFalse()
            ->and(isset($r->a))->toBeTrue()
            ->and(isset($r->naoExiste))->toBeFalse();
    });

    it('all() com exceções ignora campos que não existem (antes devolvia null)', function () {
        $r = request('POST', ['post' => ['a' => '1', 'b' => '2']]);

        expect($r->all('b, nao_existe'))->toBe(['a' => '1']);
    });

    it('parâmetros da rota vêm prontos do router', function () {
        $r = request('GET', [], ['id' => '42']);

        expect($r->getParams())->toBe(['id' => '42'])
            ->and($r->param('id'))->toBe('42')
            ->and($r->param('x', 'padrão'))->toBe('padrão');
    });
});

describe('headers, IP e token', function () {

    it('header() não diferencia maiúsculas e inclui Content-Type', function () {
        $r = request('POST', ['headers' => ['Authorization' => 'Bearer abc', 'Content-Type' => 'application/json']]);

        expect($r->header('authorization'))->toBe('Bearer abc')
            ->and($r->header('content-type'))->toBe('application/json');
    });

    it('bearerToken() devolve só o token (antes: o header inteiro)', function () {
        expect(request('GET', ['headers' => ['Authorization' => 'Bearer abc.def-123']])->bearerToken())->toBe('abc.def-123')
            ->and(request('GET', ['headers' => ['Authorization' => 'Basic xyz']])->bearerToken())->toBeNull()
            ->and(request('GET')->bearerToken())->toBeNull();
    });

    it('ip() ignora X-Forwarded-For de cliente não confiável (IP forjado)', function () {
        $r = request('GET', ['headers' => ['X-Forwarded-For' => '1.2.3.4']]);

        expect($r->ip())->toBe('203.0.113.10');
    });

    it('ip() usa X-Forwarded-For quando a conexão vem de proxy confiável', function () {
        RouterHttpRequest::setTrustedProxies(['10.0.0.0/8']);

        $r = request('GET', [
            'headers' => ['X-Forwarded-For' => '1.2.3.4, 198.51.100.7, 10.0.0.5'],
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
        ]);

        // o primeiro IP não confiável da direita para a esquerda: o 1.2.3.4 pode ter sido forjado
        expect($r->ip())->toBe('198.51.100.7');
    });

    it('proxies confiáveis aceitam IP exato e CIDR IPv6', function () {
        RouterHttpRequest::setTrustedProxies(['192.168.1.1', '2001:db8::/32']);

        expect(request('GET', ['headers' => ['X-Forwarded-For' => '8.8.8.8'], 'server' => ['REMOTE_ADDR' => '192.168.1.1']])->ip())->toBe('8.8.8.8')
            ->and(request('GET', ['headers' => ['X-Forwarded-For' => '8.8.4.4'], 'server' => ['REMOTE_ADDR' => '2001:db8::1']])->ip())->toBe('8.8.4.4')
            ->and(request('GET', ['headers' => ['X-Forwarded-For' => '8.8.4.4'], 'server' => ['REMOTE_ADDR' => '2001:db9::1']])->ip())->toBe('2001:db9::1');
    });

    it('isSecure() só confia em X-Forwarded-Proto de proxy confiável', function () {
        expect(request('GET', ['headers' => ['X-Forwarded-Proto' => 'https']])->isSecure())->toBeFalse();

        RouterHttpRequest::setTrustedProxies(['203.0.113.10']);

        expect(request('GET', ['headers' => ['X-Forwarded-Proto' => 'https']])->isSecure())->toBeTrue()
            ->and(request('GET', ['server' => ['HTTPS' => 'on']])->isSecure())->toBeTrue();
    });
});
