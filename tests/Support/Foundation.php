<?php

/*
|--------------------------------------------------------------------------
| Helpers do Foundation
|--------------------------------------------------------------------------
*/

/** Cria um diretório temporário único e devolve o caminho (removido no fim do processo). */
function tempDir(string $prefix = 'wep'): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    register_shutdown_function(fn () => removeDir($dir));

    return $dir;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) return;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}

/** Cria um .env temporário com o conteúdo informado e devolve o caminho. */
function tempEnv(string $content): string
{
    $file = tempDir('env') . '/.env';
    file_put_contents($file, $content);

    return $file;
}

class TestApp extends \Wep\Core {}

/**
 * Aplicação temporária iniciada uma única vez por processo (Core::start define constantes globais).
 *
 * @return array{root: string, config: array, instance: TestApp}
 */
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

    register_shutdown_function(fn () => forgetEnv('ENV', 'APP_URL', 'WEP_CORE_FLAG', 'WEP_CORE_EMPTY'));

    return $app = ['root' => $root, 'config' => $config, 'instance' => $instance];
}

/** Remove variáveis de ambiente criadas pelos testes (getenv, $_ENV e $_SERVER). */
function forgetEnv(string ...$names): void
{
    foreach ($names as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
