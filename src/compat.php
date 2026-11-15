<?php

/*
|--------------------------------------------------------------------------
| Compatibilidade com o namespace antigo (App\Core\ → Wep\)
|--------------------------------------------------------------------------
| Até a 1.x o Core usava o namespace App\Core\. Este autoloader cria, sob demanda,
| um alias App\Core\X → Wep\X, então código existente (use App\Core\Model, implements
| App\Core\Http\iMiddleware, route-maps.build.php gerados) continua funcionando.
|
| Registrado com prepend: precisa rodar ANTES do Composer. Templates antigos mapeiam
| "App\\Core\\" direto para vendor/wep/framework/src/ e, sem isso, o Composer incluiria
| o mesmo arquivo de novo (fatal "cannot redeclare").
|
| Classes renomeadas ou movidas entram em $moved (nome antigo → nome novo).
|
| @deprecated mantido na 2.x; será removido numa versão maior futura. Use os nomes Wep\.
*/

spl_autoload_register(static function (string $class): void {
    static $prefix = 'App\\Core\\';

    /** @var array<string, string> nome antigo (sem o prefixo) => classe nova */
    static $moved = [];

    if (strncmp($class, $prefix, 9) !== 0) {
        return;
    }

    $relative = substr($class, 9);
    $target = $moved[$relative] ?? 'Wep\\' . $relative;

    if (class_exists($target) || interface_exists($target) || trait_exists($target)) {
        class_alias($target, $class);
    }
}, true, true);
