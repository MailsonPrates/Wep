<?php

use Wep\DB;
use Wep\Router\Http\RouterHttpRequest;

/*
|--------------------------------------------------------------------------
| Helpers do CRUD automático (Módulo 3)
|--------------------------------------------------------------------------
*/

/**
 * App de teste + módulo Produto + banco SQLite próprio (o Handler do Model guarda o PDO
 * da primeira chamada, então o banco é criado uma única vez e os dados são recriados).
 */
function crudDatabase(bool $reset = true): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $root = testApp()['root'];
        $dir = "$root/src/Modules/Produto/config";

        if (!is_dir($dir)) mkdir($dir, 0777, true);

        file_put_contents("$dir/module.php", "<?php return " . var_export([
            'db' => ['table' => 'produtos'],
            'api' => ['hidden' => ['segredo'], 'max_limit' => 3],
            'routes' => [],
        ], true) . ";");

        require_once __DIR__ . '/Fixtures/Produto.php';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, preco REAL, status TEXT, segredo TEXT, password TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, password TEXT)');

        $previous = DB::$pdo;
        DB::$pdo = $pdo;
        \App\Modules\Produto\Produto::table(); // o Handler do Model fica com este PDO
        DB::$pdo = $previous;
    }

    if (!$reset) return $pdo;

    $pdo->exec('DELETE FROM produtos');
    $pdo->exec('DELETE FROM users');

    foreach ([[1, 'Caneta', 2.5, 'on'], [2, 'Caderno', 15, 'on'], [3, 'Mochila', 120, 'off'], [4, 'Lápis', 1, 'on'], [5, 'Régua', 3, 'off']] as [$id, $nome, $preco, $status]) {
        $pdo->exec("INSERT INTO produtos VALUES ($id, '$nome', $preco, '$status', 'segredo-$id', 'hash-produto-$id')");
    }

    $pdo->exec("INSERT INTO users VALUES (1, 'admin', '\$2y\$10\$HASHDOADMIN')");

    return $pdo;
}

/**
 * Simula a requisição atual com o payload JSON do cliente e devolve o request.
 */
function clientRequest(array $payload, string $method = 'POST'): RouterHttpRequest
{
    fakeRequest($method, '/produto/api/x/', [
        'body' => json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    $request = new RouterHttpRequest('http://app.test', '/produto/api/x/', []);
    RouterHttpRequest::setCurrent($request);

    return $request;
}

/** Executa o handler padrão do Controller e devolve a resposta JSON decodificada. */
function crudCall(string $method, array $payload): array
{
    $request = clientRequest($payload);

    ob_start();
    (new \App\Modules\Produto\ProdutoApi())->callDefault($method, $request);

    return json_decode(ob_get_clean(), true) ?? [];
}
