<?php

use Wep\Router\Exceptions\BadMiddlewareExecuteException;
use Wep\Router\Exceptions\NotFoundException;
use Wep\Router\Middlewares\MiddlewareRoute;
use Wep\Router\Route;

class RouterTestMiddleware implements \Wep\Http\Middleware
{
    public static $seen = null;

    public function handle($request, $next)
    {
        self::$seen = $request;
        $next();
        return true;
    }
}

class RouterTestLegacyMiddleware implements \App\Core\Http\iMiddleware
{
    public function handle($request, $next)
    {
        $next();
        return true;
    }
}

/** Registra rotas novas e simula a requisição. */
function router(string $method, string $uri, callable $routes, array $options = []): string
{
    fakeRequest($method, $uri, $options);
    Route::start('http://app.test', '@');
    $routes();

    return dispatch();
}

$echo = fn (string $label) => function ($request) use ($label) {
    echo $label . json_encode($request->getParams());
};

afterEach(function () {
    resetRequest();
    MiddlewareRoute::setMiddlewares([]);
    RouterTestMiddleware::$seen = null;
});

describe('matching', function () use ($echo) {

    it('rota estática vence dinâmica, em qualquer ordem de registro', function () use ($echo) {
        $a = router('GET', '/user/create/', function () use ($echo) {
            Route::get('/user/{id}', $echo('dinamica'));
            Route::get('/user/create', $echo('estatica'));
        });

        $b = router('GET', '/user/create/', function () use ($echo) {
            Route::get('/user/create', $echo('estatica'));
            Route::get('/user/{id}', $echo('dinamica'));
        });

        expect($a)->toBe('estatica[]')->and($b)->toBe('estatica[]');
    });

    it('entre dinâmicas vence a primeira registrada (antes: a última)', function () use ($echo) {
        expect(router('GET', '/p/x/', function () use ($echo) {
            Route::get('/p/{a}', $echo('primeira'));
            Route::get('/p/{b}', $echo('segunda'));
        }))->toBe('primeira{"a":"x"}');
    });

    it('captura parâmetros pelos grupos da regex', function () use ($echo) {
        expect(router('GET', '/admin/user/42/edit/', fn () => Route::get('/admin/user/{id}/edit', $echo('')) ))
            ->toBe('{"id":"42"}')
            // antes o array_diff de segmentos perdia o parâmetro quando o valor repetia um segmento
            ->and(router('GET', '/user/user/', fn () => Route::get('/user/{name}', $echo(''))))
            ->toBe('{"name":"user"}');
    });

    it('parâmetro opcional', function () use ($echo) {
        $routes = fn () => Route::get('/post/{slug?}', $echo(''));

        expect(router('GET', '/post/', $routes))->toBe('[]')
            ->and(router('GET', '/post/ola-mundo/', $routes))->toBe('{"slug":"ola-mundo"}');
    });

    it('where() restringe o parâmetro', function () use ($echo) {
        $routes = function () use ($echo) {
            Route::get('/n/{id}', $echo('numero'))->whereNumber('id');
            Route::get('/n/{slug}', $echo('texto'));
        };

        expect(router('GET', '/n/10/', $routes))->toBe('numero{"id":"10"}')
            ->and(router('GET', '/n/abc/', $routes))->toBe('texto{"slug":"abc"}');
    });

    it('trechos literais são escapados (antes "." casava com qualquer caractere)', function () use ($echo) {
        $routes = function () use ($echo) {
            Route::get('/files/{name}.json', $echo('json'));
            Route::fallback(fn () => print('fallback'));
        };

        expect(router('GET', '/files/a.json', $routes))->toBe('json{"name":"a"}')
            ->and(router('GET', '/files/aXjson', $routes))->toBe('fallback');
    });

    it('barra final é opcional; raiz funciona', function () use ($echo) {
        $routes = function () use ($echo) {
            Route::get('/', $echo('raiz'));
            Route::get('/sobre', $echo('sobre'));
        };

        expect(router('GET', '/', $routes))->toBe('raiz[]')
            ->and(router('GET', '/sobre', $routes))->toBe('sobre[]')
            ->and(router('GET', '/sobre/', $routes))->toBe('sobre[]');
    });

    it('HEAD usa as rotas GET; método desconhecido cai no fallback sem warning', function () use ($echo) {
        $routes = function () use ($echo) {
            Route::get('/x', $echo('get'));
            Route::fallback(fn () => print('fallback'));
        };

        expect(router('HEAD', '/x/', $routes))->toBe('get[]')
            ->and(router('PROPFIND', '/x/', $routes))->toBe('fallback');
    });

    it('sem rota e sem fallback lança NotFoundException (404)', function () {
        router('GET', '/nada/', fn () => Route::get('/x', fn () => null));
    })->throws(NotFoundException::class);

    it('Route::any aplica o prefixo do grupo e não registra em REDIRECT', function () use ($echo) {
        $output = router('PUT', '/api/ping/', function () use ($echo) {
            Route::prefix('/api')->group(fn () => Route::any('/ping', $echo('any')));
        });

        expect($output)->toBe('any[]')
            ->and(Route::$collection->getRoutesOf('REDIRECT'))->toBe([]);
    });
});

describe('request e middlewares', function () {

    it('o middleware e a action recebem o mesmo request (um por ciclo)', function () {
        MiddlewareRoute::setMiddlewares(['teste' => RouterTestMiddleware::class]);
        $actionRequest = null;

        router('POST', '/save/7/', function () use (&$actionRequest) {
            Route::post('/save/{id}', function ($request) use (&$actionRequest) {
                $actionRequest = $request;
            })->middleware('teste');
        }, ['body' => '{"name":"Ana"}', 'headers' => ['Content-Type' => 'application/json']]);

        expect($actionRequest)->toBe(RouterTestMiddleware::$seen)
            ->and($actionRequest->name)->toBe('Ana')
            ->and($actionRequest->id)->toBe('7');
    });

    it('middlewares que implementam o nome antigo App\\Core\\Http\\iMiddleware continuam funcionando', function () {
        MiddlewareRoute::setMiddlewares(['legado' => RouterTestLegacyMiddleware::class]);

        expect(router('GET', '/x/', fn () => Route::get('/x', fn () => print('ok'))->middleware('legado')))->toBe('ok');
    });

    it('middleware desconhecido falha fechado (antes a rota ficava sem proteção)', function () {
        router('GET', '/admin/', fn () => Route::get('/admin', fn () => print('dados sigilosos'))->middleware('isLoged'));
    })->throws(BadMiddlewareExecuteException::class, "Middleware 'isLoged' não encontrado");

    it('a mensagem de erro do middleware desconhecido não chega a executar a action', function () {
        $executed = false;

        try {
            router('GET', '/admin/', fn () => Route::get('/admin', function () use (&$executed) { $executed = true; })->middleware('naoExiste'));
        } catch (BadMiddlewareExecuteException) {
        }

        expect($executed)->toBeFalse();
    });
});
