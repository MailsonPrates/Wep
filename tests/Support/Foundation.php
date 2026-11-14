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

/** Remove variáveis de ambiente criadas pelos testes (getenv, $_ENV e $_SERVER). */
function forgetEnv(string ...$names): void
{
    foreach ($names as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
