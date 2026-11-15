<?php

use Composer\Autoload\ClassLoader;

describe('alias App\\Core\\ → Wep\\', function () {

    it('resolve classes pelo nome antigo', function () {
        expect(class_exists('App\\Core\\Response'))->toBeTrue()
            ->and(new \App\Core\Response())->toBeInstanceOf(\Wep\Response::class);
    });

    it('resolve traits e interfaces pelo nome antigo', function () {
        expect(trait_exists('App\\Core\\Model'))->toBeTrue()
            ->and(interface_exists('App\\Core\\Http\\iMiddleware'))->toBeTrue();

        $middleware = new class implements \App\Core\Http\iMiddleware {
            public function handle($request, $next) {}
        };

        expect($middleware)->toBeInstanceOf(\Wep\Http\iMiddleware::class);
    });

    it('ignora classes fora do prefixo e nomes inexistentes', function () {
        expect(class_exists('App\\Core\\NaoExiste'))->toBeFalse()
            ->and(class_exists('App\\Modules\\Qualquer'))->toBeFalse();
    });

    it('roda antes do Composer (templates antigos mapeiam "App\\\\Core\\\\" direto para src/)', function () {
        // Com o mapeamento manual do template antigo, se o Composer atendesse App\Core\X antes
        // do alias, incluiria de novo o arquivo de Wep\X já carregado: "cannot redeclare class".
        $loaders = spl_autoload_functions();

        $compat = array_key_first(array_filter($loaders, fn ($l) => $l instanceof Closure
            && str_ends_with((new ReflectionFunction($l))->getFileName(), 'compat.php')));

        $composer = array_key_first(array_filter($loaders, fn ($l) => is_array($l) && $l[0] instanceof ClassLoader));

        expect($compat)->not->toBeNull()
            ->and($composer)->not->toBeNull()
            ->and($compat)->toBeLessThan($composer);
    });
});
