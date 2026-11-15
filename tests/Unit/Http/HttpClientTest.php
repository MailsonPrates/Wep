<?php

use Wep\Engine\Builder\Vendor\Endpoint;
use Wep\Http\HttpRequest;

function withStatus(int $code, string $error = ''): HttpRequest
{
    $request = new HttpRequest('https://api.test');
    (new ReflectionProperty($request, 'httpCode'))->setValue($request, $code);
    (new ReflectionProperty($request, 'error'))->setValue($request, $error);

    return $request;
}

describe('HttpRequest', function () {

    it('atalhos estáticos funcionam (antes: fatal "Class Request not found")', function () {
        // sem servidor: só precisa não dar fatal e devolver um Response de erro
        $result = HttpRequest::get('http://127.0.0.1:1/');

        expect($result->error)->toBeTrue();
    });

    it('só http/https: file:// é recusado', function () {
        $request = (new HttpRequest('file:///etc/hosts'))->execute();

        expect($request->isError())->toBeTrue()
            ->and($request->getResponseBody())->toBe('');
    });

    it('isError(): status >= 400 ou falha de conexão (antes "> 339", incluía 3xx)', function () {
        expect(withStatus(200)->isError())->toBeFalse()
            ->and(withStatus(302)->isError())->toBeFalse()
            ->and(withStatus(350)->isError())->toBeFalse()
            ->and(withStatus(404)->isError())->toBeTrue()
            ->and(withStatus(500)->isError())->toBeTrue()
            ->and(withStatus(0, 'Could not resolve host')->isError())->toBeTrue();
    });

    it('setQuery/setParams aceitam 0 e "" (antes TypeError)', function () {
        $request = (new HttpRequest('https://api.test/itens/{id}'))
            ->setQuery('page', 0)
            ->setQuery(['q' => ''])
            ->setParams('id', 0);

        expect($request->getOptions()['query'])->toBe(['page' => 0, 'q' => ''])
            ->and($request->getUrl())->toBe('https://api.test/itens/0');
    });

    it('disableSSL() / ssl => false: fora de DEV a verificação continua ligada', function () {
        testApp(); // ENV=PROD

        expect((new HttpRequest('https://x'))->disableSSL()->getOptions()['ssl'])->toBeTrue()
            ->and((new HttpRequest(['url' => 'https://x', 'ssl' => false]))->getOptions()['ssl'])->toBeTrue();
    });
});

describe('Endpoint (vendor)', function () {

    beforeEach(function () {
        testApp();
        putenv('WEP_VENDOR_KEY=segredo');
    });

    afterEach(fn () => putenv('WEP_VENDOR_KEY'));

    it('resolve {{ env.X }} e deixa outros placeholders como estão (antes: recursão infinita)', function () {
        expect(Endpoint::parseEnvVariables('Bearer {{ env.WEP_VENDOR_KEY }}'))->toBe('Bearer segredo')
            ->and(Endpoint::parseEnvVariables('{{env.WEP_VENDOR_KEY}}/x/{{ env.WEP_VENDOR_KEY }}'))->toBe('segredo/x/segredo')
            ->and(Endpoint::parseEnvVariables('https://api/{{ versao }}/x'))->toBe('https://api/{{ versao }}/x')
            ->and(Endpoint::parseEnvVariables('{{ env.NAO_EXISTE_XYZ }}'))->toBe('');
    });

    it('headers sem quebra de linha (injeção de header)', function () {
        putenv("WEP_VENDOR_KEY=abc\r\nX-Injetado: 1");

        expect(Endpoint::getParsedHeaders(['Authorization' => 'Bearer {{ env.WEP_VENDOR_KEY }}', "X-A\r\nB" => 'v']))
            ->toBe(['Authorization: Bearer abc X-Injetado: 1', 'X-AB: v']);
    });

    it('hooks: "-nome" remove hooks herdados (antes pulava itens)', function () {
        $merge = new ReflectionMethod(Endpoint::class, 'mergeHooks');

        $hooks = $merge->invoke(null,
            ['beforeRequest' => ['-a', '-b', 'd']],
            ['beforeRequest' => ['a', 'b', 'c']]
        );

        expect($hooks['beforeRequest'])->toBe(['c', 'd']);
    });
});
