<?php

use Wep\DB;

/*
|--------------------------------------------------------------------------
| Helpers do módulo DB
|--------------------------------------------------------------------------
| Carregado por tests/Pest.php. Usa as classes reais do Core (autoload do Composer).
|
| Formato do Wep\Response:
|   sucesso: {error: false, data: ..., ...params}   (params ficam no nível raiz)
|   erro:    {error: true, message?: ..., ...params}
*/
function succeeded($response): bool
{
    return is_object($response) && isset($response->error) && $response->error === false;
}

function rows($response)
{
    return $response->data;
}

function meta($response, string $key)
{
    return ((array) $response)[$key] ?? null;
}

function errorOf($response): string
{
    return (string) ($response->message ?? '');
}

/** Coluna "id" (ou outra) de um resultado de lista. */
function column($response, string $col = 'id'): array
{
    return array_column(rows($response), $col);
}

/*
|--------------------------------------------------------------------------
| Banco em memória (SQLite) com dados conhecidos
|--------------------------------------------------------------------------
*/
function freshDatabase(): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException("Os testes do DB usam SQLite em memória: habilite 'extension=pdo_sqlite' no php.ini.");
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, email TEXT, age INT, stock INT, status TEXT, note TEXT
    )");
    $pdo->exec("CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT, total REAL, number TEXT
    )");

    $users = $pdo->prepare("INSERT INTO users (name, email, age, stock, status, note) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ([
        ['Ana',     'ana@x.com', 30, 5, 'on',  null],
        ['Bruno',   'bru@x.com', 25, 0, 'off', null],
        ["O'Brien", 'ob@x.com',  41, 9, 'on',  'x'],
        ['Carla',   'car@x.com', 19, 2, 'on',  ''],
        ['Davi',    'dav@x.com', 60, 7, 'off', null],
    ] as $row) {
        $users->execute($row);
    }

    $orders = $pdo->prepare("INSERT INTO orders (user_id, total, number) VALUES (?, ?, ?)");
    foreach ([[1, 100.5, 'A1'], [1, 20, 'A2'], [2, 55, 'B1'], [4, 10, 'C1']] as $row) {
        $orders->execute($row);
    }

    DB::$pdo = $pdo;

    // configurações estáticas voltam ao padrão a cada teste
    DB::setConfig(['debug' => false, 'null_as_empty' => true, 'expose_errors' => false, 'multi_statements' => false]);

    return $pdo;
}

function userCount(): int
{
    return (int) DB::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function userStatuses(): array
{
    return DB::$pdo->query('SELECT status FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

/*
|--------------------------------------------------------------------------
| Tentativas de ataque usadas em tests/Feature/SecurityTest.php
| (cada uma devolve o Response; nenhuma pode ter efeito no banco)
|--------------------------------------------------------------------------
*/
function attack(string $name)
{
    switch ($name) {
        case 'orderBy com ;':
            return DB::table('users')->orderBy('id; DROP TABLE users')->get('id');
        case 'orderBy com subselect':
            return DB::table('users')->orderBy('id, (SELECT name FROM users LIMIT 1)')->get('id');
        case 'orderBy com direção maliciosa':
            return DB::table('users')->orderBy('id', 'desc; DROP TABLE users')->get('id');
        case 'where com coluna maliciosa':
            return DB::table('users')->where('id = 1 OR 1=1 --', 'x')->get('id');
        case 'campos do select':
            return DB::table('users')->get('id FROM users; DROP TABLE users; --');
        case 'limit':
            return DB::table('users')->limit('1; DROP TABLE users')->get('id');
        case 'nome da tabela':
            return DB::table('users`; DROP TABLE users; -- ')->get('id');
        case 'tabela do join':
            return DB::table('users')->leftJoin('orders ON 1=1 --', 'a', 'b')->get('id');
        case 'ON do join':
            return DB::table('users')->leftJoin('orders', 'user_id', 'users.id OR 1=1')->get('id');
        case 'coluna do update':
            return DB::table('users')->where('id', 1)->update(["status = 'x', age" => 1]);
        case 'coluna do insert':
            return DB::table('users')->insert(["id) VALUES (1); DROP TABLE users; --" => 1]);
        case 'operador inválido':
            return DB::table('users')->where('id', 'banana', 5)->get('id');
        case 'between sem dois valores':
            return DB::table('users')->where('age', 'between', [1])->get('id');
        case 'modo de fetch inválido':
            return DB::table('users')->fetch('x; DROP TABLE users')->get('id');
        case 'valor array em operador escalar':
            return DB::table('users')->where('id', '>', [1, 2])->get('id');
    }

    throw new InvalidArgumentException("ataque desconhecido: $name");
}
