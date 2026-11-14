<?php

use Wep\Core;

/*
 * Core::start() define constantes globais e estado estático: roda uma única vez por processo.
 * Os testes deste arquivo compartilham a mesma aplicação temporária (testApp()).
 */

class TestApp extends Core {}

function testApp(): array
{
    static $app = null;

    if ($app !== null) return $app;

    $root = tempDir('app');

    foreach (['config', 'public/assets/css', 'storage/builds', 'storage/log', 'storage/cache', 'src/Modules'] as $dir) {
        mkdir("$root/$dir", 0777, true);
    }

    file_put_contents("$root/config/.env", "ENV=PROD\nAPP_URL=http://wep.test\nWEP_CORE_FLAG=false\nWEP_CORE_EMPTY=\n");
    file_put_contents("$root/config/data.php", "<?php return ['empresa' => 'ACME', 'vazio' => ''];");
    file_put_contents("$root/public/assets/css/main.css", 'body{}');
    touch("$root/public/assets/css/main.css", 1700000000);

    $config = [
        'name' => 'App de Teste',
        'title' => '',
        'dir' => ['root' => $root],
        'timezone' => 'America/Sao_Paulo',
        'error_handler' => false,
    ];

    $instance = TestApp::start($config);

    return $app = ['root' => $root, 'config' => $config, 'instance' => $instance];
}

afterAll(fn () => forgetEnv('ENV', 'APP_URL', 'WEP_CORE_FLAG', 'WEP_CORE_EMPTY'));

describe('Core::start', function () {

    it('lança exceção clara quando falta dir.root (antes devolvia null e o run() quebrava)', function () {
        expect(Core::isStarted())->toBeFalse();

        TestApp::start(['name' => 'sem root']);
    })->throws(InvalidArgumentException::class, 'dir.root');

    it('inicia e devolve uma instância da classe da aplicação', function () {
        expect(testApp()['instance'])->toBeInstanceOf(TestApp::class)
            ->and(Core::isStarted())->toBeTrue();
    });

    it('define as constantes legadas', function () {
        $root = testApp()['root'];

        expect(DIR_ROOT)->toBe($root)
            ->and(DIR_CONFIG)->toBe("$root/config")
            ->and(ROUTE_MAPS_BUILD_FILENAME_BACK)->toBe("$root/storage/builds/route-maps.build.php")
            ->and(APP_MODULES_NAMESPACE)->toBe('App\\Modules\\')
            ->and(ENV)->toBe('PROD')
            ->and(ENV_DEV)->toBeFalse()
            ->and(APP_URL)->toBe('http://wep.test')
            ->and(DEFAULT_VIEW_CONTROLLER)->toBe('Wep\\View\\View@handle');
    });

    it('um segundo start() não gera warning de constante redefinida', function () {
        testApp();

        expect(TestApp::start(testApp()['config']))->toBeInstanceOf(TestApp::class);
    });

    it('aplica o timezone da config', function () {
        testApp();

        expect(date_default_timezone_get())->toBe('America/Sao_Paulo');
    });
});

describe('Core::config', function () {

    beforeEach(fn () => testApp());

    it('lê a config do app, com fallback e alternativas', function () {
        expect(TestApp::config('name'))->toBe('App de Teste')
            ->and(TestApp::config('title|name'))->toBe('App de Teste')
            ->and(TestApp::config('title', 'fb'))->toBe('fb')
            ->and(TestApp::config('dir.root'))->toBe(testApp()['root'])
            ->and(TestApp::config())->toBe(testApp()['config']);
    });

    it('lê o .env nas três formas', function () {
        expect(TestApp::config('env.app_url'))->toBe('http://wep.test')
            ->and(TestApp::config('env', 'APP_URL'))->toBe('http://wep.test')
            ->and(TestApp::config('env', 'nao_existe', 'fb'))->toBe('fb')
            ->and(TestApp::env('app_url'))->toBe('http://wep.test');
    });

    it('respeita false do .env (antes caía no fallback)', function () {
        expect(TestApp::config('env.wep_core_flag', true))->toBeFalse()
            ->and(TestApp::config('env.wep_core_empty', 'fb'))->toBe('fb');
    });

    it('"env." só vale como prefixo', function () {
        expect(TestApp::config('myenv.x', 'fb'))->toBe('fb');
    });
});

describe('Core: data, path e assets', function () {

    beforeEach(fn () => testApp());

    it('data()', function () {
        expect(TestApp::data('empresa'))->toBe('ACME')
            ->and(TestApp::data('nao_existe', 'padrão'))->toBe('padrão')
            ->and(TestApp::data('vazio|empresa'))->toBe('ACME')
            ->and(TestApp::data()->empresa)->toBe('ACME');
    });

    it('path()', function () {
        $root = testApp()['root'];

        expect(TestApp::path())->toBe($root)
            ->and(TestApp::path('config', '.env'))->toBe("$root/config/.env")
            ->and(TestApp::path('/config/.env'))->toBe("$root/config/.env");
    });

    it('assets() não depende do caminho físico da raiz (bug com /public_html no cPanel)', function () {
        expect(TestApp::assets())->toBe('/assets')
            ->and(TestApp::assets('css/main.css'))->toBe('/assets/css/main.css')
            ->and(TestApp::assets('/css/main.css', 123))->toBe('/assets/css/main.css?v=123');
    });

    it('assets(..., true) usa a data de modificação do arquivo e respeita query existente', function () {
        expect(TestApp::assets('css/main.css', true))->toBe('/assets/css/main.css?v=1700000000')
            ->and(TestApp::assets('css/main.css?x=1', 5))->toBe('/assets/css/main.css?x=1&v=5');
    });
});

describe('Core::syncEnvCache', function () {

    it('fora de DEV compila o .env em storage/builds/env.build.php', function () {
        $root = testApp()['root'];
        $file = "$root/storage/builds/env.build.php";

        touch("$root/config/.env", time() - 10);

        expect(TestApp::syncEnvCache())->toBeTrue()
            ->and(include $file)->toMatchArray(['ENV' => 'PROD', 'APP_URL' => 'http://wep.test', 'WEP_CORE_FLAG' => false]);
    });
});
