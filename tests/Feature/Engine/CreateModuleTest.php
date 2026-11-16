<?php

use Wep\Engine\Builder\Snippets\Module;

describe('php app create module', function () {

    it('módulo novo: login e permissão, path com barra, sem CRUD automático e Api tipada', function () {
        $root = testApp()['root'];
        $dir = "$root/src/Modules/Vendas/PedidoItem";

        try {
            $result = Module::create(['params' => 'Vendas/PedidoItem']);

            $config = include "$dir/config/module.php";
            $api = file_get_contents("$dir/PedidoItemApi.php");

            exec('php -l ' . escapeshellarg("$dir/PedidoItemApi.php"), $lint, $code);
        } finally {
            removeDir("$root/src/Modules/Vendas");
        }

        expect($result->error)->toBeFalse()
            ->and($config['routes']['path'])->toBe('/vendas/pedido-item')
            ->and($config['routes']['use_middlewares'])->toBe(['isLogged', 'checkPermission'])
            ->and($config['routes'])->not->toHaveKey('methods')
            ->and($api)->toContain('public function exemplo(RouterHttpRequest $request, Response $response)')
            ->and($api)->toContain('use Wep\Router\Http\RouterHttpRequest;')
            ->and($code)->toBe(0);
    });
});
