<?php

/*
|--------------------------------------------------------------------------
| Bootstrap dos testes do Core
|--------------------------------------------------------------------------
| As classes vêm do autoload do Composer (bootstrap do phpunit.xml).
| Helpers de cada módulo ficam em tests/Support e são carregados aqui.
*/

// o Core registra falhas no error_log; nos testes isso só polui a saída
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

require_once __DIR__ . '/Support/Database.php';
require_once __DIR__ . '/Support/Foundation.php';
require_once __DIR__ . '/Support/Http.php';
