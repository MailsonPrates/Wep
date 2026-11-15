<?php

use Wep\Engine\Builder\BuildFile;
use Wep\Engine\Update;
use Wep\Router\Route;
use Wep\Router\RouteMap;
use Wep\Router\RouteRegistrar;

/**
 * App de teste com o módulo Pedido (hooks, rota com apóstrofo e constantes).
 */
function builderApp(array $hooks = ['each_route' => ['MarcaA', 'MarcaB', 'NaoDevolve']]): string
{
    $root = testApp()['root'];
    $dir = "$root/src/Modules/Pedido/config";

    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_dir("$root/src/Modules/Vendor")) mkdir("$root/src/Modules/Vendor", 0777, true);

    require_once dirname(__DIR__, 2) . '/Support/Fixtures/Pedido.php';

    file_put_contents("$dir/module.php", '<?php return ' . var_export([
        'title' => 'Pedidos',
        'constants' => [
            'PEDIDO_DIR' => '{DIR_ROOT}/pedidos',
            'PEDIDO_TEXTO' => "it's \"ok\"",
            'PEDIDO_LIMITE' => 10,
        ],
        'hooks' => $hooks,
        'routes' => [
            'path' => '/pedido',
            'groups' => [
                ['path' => '/', 'view' => 'index'],
                ['path' => "/o'brien", 'view' => 'Obrien'],
            ],
        ],
    ], true) . ';');

    return $root;
}

function buildFile(string $name): string
{
    return testApp()['root'] . '/storage/builds/' . $name;
}

afterAll(function () {
    $root = testApp()['root'];
    removeDir("$root/src/Modules/Pedido");
    foreach (glob("$root/storage/builds/*") ?: [] as $file) is_file($file) && unlink($file);
    RouteMap::flush();
});

describe('php app update', function () {

    it('roda TODOS os hooks each_route, em cadeia (antes só o primeiro)', function () {
        builderApp();

        $result = Update::handle();
        $route = (include buildFile('route-maps.php'))['/pedido/']['GET'];

        expect($result->error)->toBeFalse()
            ->and($route['custom'])->toBe(['a' => true, 'b' => true]);
    });

    it('o build do backend é dados (var_export), não código montado por concatenação', function () {
        builderApp();
        Update::handle();

        $backend = include buildFile('route-maps.build.php');

        expect($backend)->toBeArray()
            ->and($backend['routes'])->toContain(['get', "/pedido/o'brien/", 'Wep\\View\\View@handle', []])
            ->and($backend['fallback'])->toBe('Wep\\View\\View@handle');
    });

    it('rota com apóstrofo é registrada (antes era erro de sintaxe no arquivo gerado)', function () {
        builderApp();
        Update::handle();

        fakeRequest('GET', "/pedido/o'brien/");
        Route::start('http://app.test', '@');
        RouteRegistrar::register(include buildFile('route-maps.build.php'));

        [$route] = Route::$collection->findRoute("/pedido/o'brien/", 'GET');

        expect($route)->not->toBeNull()
            ->and($route->getOriginalRoute())->toBe("/pedido/o'brien");

        resetRequest();
    });

    it('arquivos gerados usam \n (antes \r sozinho) e o JS escapa paths e nomes', function () {
        builderApp();
        Update::handle();

        $front = file_get_contents(buildFile('route-maps.build.js'));

        expect(file_get_contents(buildFile('route-maps.build.php')))->not->toMatch("/\r(?!\n)/")
            ->and($front)->not->toMatch("/\r(?!\n)/")
            ->and($front)->toContain('path={"/pedido/o\'brien/"}');
    });

    it('constantes: placeholders e apóstrofos viram dados seguros', function () {
        builderApp();
        Update::handle();

        $constants = include buildFile('constants.build.php');

        expect($constants['PEDIDO_TEXTO'])->toBe(['value' => "it's \"ok\""])
            ->and($constants['PEDIDO_LIMITE'])->toBe(['value' => 10])
            ->and($constants['PEDIDO_DIR'])->toBe(['parts' => [['const', 'DIR_ROOT'], ['text', '/pedidos']]]);
    });

    it('hook que falha vira aviso e o build conclui', function () {
        builderApp(['after_build' => ['Falha', 'NaoExiste']]);

        $result = Update::handle();

        expect($result->error)->toBeFalse()
            ->and($result->warnings)->toContain("Hook after_build 'Falha' falhou: banco indisponível")
            ->and(implode("\n", $result->warnings))->toContain("Hook after_build 'NaoExiste' não encontrado");
    });

    it('--strict: hook que falha aborta o update', function () {
        builderApp(['after_build' => ['Falha']]);

        $result = Update::handle(['options' => ['--strict']]);

        expect($result->error)->toBeTrue()
            ->and($result->message)->toContain('banco indisponível');
    });

    it('rota duplicada entre módulos vira aviso', function () {
        $root = builderApp();
        $dir = "$root/src/Modules/PedidoCopia/config";
        mkdir($dir, 0777, true);
        file_put_contents("$dir/module.php", '<?php return ' . var_export([
            'routes' => ['path' => '/pedido', 'groups' => [['path' => '/', 'view' => 'index']]],
        ], true) . ';');

        try {
            $result = Update::handle();
        } finally {
            removeDir("$root/src/Modules/PedidoCopia");
        }

        expect(implode("\n", $result->warnings))->toContain('Rota duplicada: GET /pedido/');
    });
});

describe('BuildFile', function () {

    it('grava de forma atômica, sem deixar temporários', function () {
        $dir = tempDir('build');

        expect(BuildFile::php("$dir/x.php", ['a' => "it's"]))->toBeTrue()
            ->and(include "$dir/x.php")->toBe(['a' => "it's"])
            ->and(glob("$dir/*.tmp"))->toBe([])
            ->and(BuildFile::write("$dir/sub/y.js", 'ok'))->toBeTrue();
    });

    it('identifier() e js()', function () {
        expect(BuildFile::identifier('Admin/User-Group'))->toBe('AdminUserGroup')
            ->and(BuildFile::identifier('1abc', 'View'))->toBe('View1abc')
            ->and(BuildFile::identifier('`);alert(1)//'))->toBe('alert1')
            ->and(BuildFile::js("/a'b/</script>"))->not->toContain('<')
            ->and(json_decode(BuildFile::js("/a'b/</script>")))->toBe("/a'b/</script>");
    });
});
