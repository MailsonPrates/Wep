<?php

use Wep\DB;
use Wep\DB\Connection;

/*
 * Roda contra um MySQL/MariaDB real. Configure DB_TEST_HOST, DB_TEST_NAME, DB_TEST_USER e DB_TEST_PASS
 * (no phpunit.xml ou no ambiente). Sem DB_TEST_NAME (ou sem conseguir conectar) os testes são pulados.
 * Use um banco descartável: as tabelas items, `order` e guard são recriadas a cada teste.
 */

function mysqlCredentials(array $override = []): array
{
    return $override + [
        'db_host'     => getenv('DB_TEST_HOST') ?: '127.0.0.1',
        'db_name'     => getenv('DB_TEST_NAME') ?: '',
        'db_username' => getenv('DB_TEST_USER') ?: 'root',
        'db_password' => getenv('DB_TEST_PASS') ?: '',
        'db_charset'  => 'utf8mb4',
    ];
}

function mysqlPdo(array $override = []): ?PDO
{
    if (!getenv('DB_TEST_NAME') || !extension_loaded('pdo_mysql')) return null;

    try {
        return Connection::instance()->connect(mysqlCredentials($override));
    } catch (Throwable $e) {
        return null;
    }
}

/*
 * DB::connect() lê as credenciais via Core::config("env", ...), que usa getenv(strtoupper($key)).
 * O helper define as variáveis com putenv() e restaura os valores anteriores no final.
 */
function connectWith(array $env): void
{
    $credentials = mysqlCredentials();
    $env += [
        'db_host' => $credentials['db_host'],
        'db_name' => $credentials['db_name'],
        'db_username' => $credentials['db_username'],
        'db_password' => $credentials['db_password'],
        'db_charset' => 'utf8mb4',
    ];

    $backup = [];

    foreach ($env as $key => $value) {
        $name = strtoupper($key);
        $backup[$name] = getenv($name);
        putenv("$name=$value");
    }

    DB::$pdo = null;

    try {
        DB::connect();
    } finally {
        foreach ($backup as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }
}

beforeEach(function () {
    $pdo = mysqlPdo();

    if (!$pdo) {
        $this->markTestSkipped('MySQL de teste não configurado (DB_TEST_NAME/USER/PASS) ou indisponível.');
    }

    $pdo->exec('DROP TABLE IF EXISTS items, `order`, guard');
    $pdo->exec("CREATE TABLE items (
        id INT PRIMARY KEY AUTO_INCREMENT, sku VARCHAR(20) UNIQUE,
        qty INT NOT NULL DEFAULT 5, note VARCHAR(50) NULL, flag TINYINT NOT NULL DEFAULT 0, opt INT NULL)");
    $pdo->exec('CREATE TABLE `order` (id INT PRIMARY KEY AUTO_INCREMENT, item_id INT, total DECIMAL(8,2))');
    $pdo->exec('CREATE TABLE guard (id INT)');

    $this->mysql = $pdo;

    DB::$pdo = $pdo;
    DB::setConfig(['debug' => false, 'null_as_empty' => true, 'expose_errors' => false, 'multi_statements' => false]);
});

afterEach(function () {
    // Só limpa a conexão MySQL aberta neste teste. DB::$pdo é estático e pode ser o
    // SQLite deixado pelos testes de Feature (DROP com várias tabelas não existe no SQLite).
    if (isset($this->mysql)) {
        $this->mysql->exec('DROP TABLE IF EXISTS items, `order`, guard');
        DB::$pdo = null;
    }
});

describe('conexão', function () {

    it('conecta e executa uma query', function () {
        expect(rows(DB::query('SELECT 1 AS ok')))->toEqual([['ok' => 1]]);
    });

    it('não deixa a senha visível no objeto Connection (print_r/var_dump)', function () {
        $dump = print_r(Connection::instance(), true);

        if (mysqlCredentials()['db_password'] !== '') {
            expect($dump)->not->toContain(mysqlCredentials()['db_password']);
        }

        expect($dump)->not->toContain('db_password');
    });

    it('DB::connect devolve mensagem genérica quando a senha está errada', function () {
        expect(fn() => connectWith(['db_password' => 'senha-errada-xyz']))
            ->toThrow(Exception::class, 'Não foi possível conectar ao banco de dados');
    });

    it('DB::connect não vaza usuário/host do banco na mensagem', function () {
        try {
            connectWith(['db_password' => 'senha-errada-xyz']);
        } catch (Exception $e) {
            expect($e->getMessage())->not->toContain('Access denied');
            expect($e->getMessage())->not->toContain(mysqlCredentials()['db_username']);
        }
    });

    it('barra DSN injection no nome do banco, sem tentar conectar', function () {
        expect(fn() => connectWith(['db_name' => 'testdb;host=servidor-malicioso']))
            ->toThrow(Exception::class, 'Invalid database name');
    });
});

describe('erros', function () {

    it('violação de unique vira "Registro duplicado"', function () {
        DB::table('items')->insert(['sku' => 'A']);

        $r = DB::table('items')->insert(['sku' => 'A']);

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toBe('Registro duplicado');
    });

    it('com expose_errors devolve a mensagem do MySQL', function () {
        DB::setConfig(['expose_errors' => true]);
        DB::table('items')->insert(['sku' => 'A']);

        expect(errorOf(DB::table('items')->insert(['sku' => 'A'])))->toContain('Duplicate entry');
    });
});

describe('on duplicate key update', function () {

    it('atualiza as colunas pedidas, inclusive com valor 0', function () {
        DB::table('items')->insert(['sku' => 'A', 'qty' => 10, 'note' => 'first']);

        DB::table('items')->onDuplicateUpdate('qty')->insert(['sku' => 'A', 'qty' => 0]);

        expect(rows(DB::table('items')->where('sku', 'A')->get('qty')))->toEqual([['qty' => 0]]);
    });

    it('não sobrescreve com string vazia', function () {
        DB::table('items')->insert(['sku' => 'A', 'qty' => 10, 'note' => 'first']);

        $r = DB::table('items')->onDuplicateUpdate('qty', 'note')->insert(['sku' => 'A', 'qty' => 7, 'note' => '']);

        expect(succeeded($r))->toBeTrue();
        expect(rows(DB::table('items')->where('sku', 'A')->get('qty', 'note')))->toEqual([['qty' => 7, 'note' => 'first']]);
    });
});

describe('tipos', function () {

    it('grava false como 0 em TINYINT NOT NULL (antes gravava "" e falhava)', function () {
        $r = DB::table('items')->insert(['sku' => 'F', 'flag' => false]);

        expect(succeeded($r))->toBeTrue();
    });

    it('null_as_empty = true mantém o legado: NULL vira "" e falha em INT NULL no modo estrito', function () {
        $r = DB::table('items')->insert(['sku' => 'N', 'opt' => null]);

        expect(succeeded($r))->toBeFalse();
    });

    it('null_as_empty = false grava NULL de verdade', function () {
        DB::setConfig(['null_as_empty' => false]);

        $r = DB::table('items')->insert(['sku' => 'N', 'opt' => null]);

        expect(succeeded($r))->toBeTrue();
        expect(rows(DB::table('items')->where('sku', 'N')->get('opt')))->toBe([['opt' => null]]);
    });

    it('bind como string não faz "code = 0" casar com texto (VARCHAR)', function () {
        DB::table('items')->insert(['sku' => 'abc']);

        expect(rows(DB::table('items')->where('sku', 0)->get('id')))->toBe([]);
    });
});

describe('paginação e joins', function () {

    it('limit + page', function () {
        foreach (['A', 'B', 'C', 'D', 'E'] as $sku) DB::table('items')->insert(['sku' => $sku]);

        $r = DB::table('items')->orderBy('sku')->limit(2)->page(2)->get('sku');

        expect(column($r, 'sku'))->toBe(['C', 'D']);
    });

    it('join com tabela de nome reservado (`order`)', function () {
        DB::table('items')->insert(['sku' => 'A']);
        DB::table('order')->insert(['item_id' => 1, 'total' => 9.5]);

        $r = DB::table('items')->leftJoin('order', 'item_id', 'items.id')->where('items.sku', 'A')->get('items.sku', 'order.total');

        expect(succeeded($r))->toBeTrue();
        expect(rows($r))->toEqual([['sku' => 'A', 'total' => '9.50']]);
    });
});

describe('queries empilhadas (;)', function () {

    it('bloqueia a segunda instrução por padrão', function () {
        $r = DB::query('SELECT 1 AS a; DROP TABLE guard');

        expect(succeeded($r))->toBeFalse();
        expect(DB::$pdo->query("SHOW TABLES LIKE 'guard'")->fetch())->not->toBeFalse();
    });

    it('com multi_statements = true volta a permitir (opt-in explícito)', function () {
        $pdo = mysqlPdo(['db_multi_statements' => true]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS guard (id INT)');
        DB::$pdo = $pdo;

        DB::query('SELECT 1 AS a; DROP TABLE guard');

        expect($pdo->query("SHOW TABLES LIKE 'guard'")->fetch())->toBeFalse();
    });
});

describe('queries customizadas', function () {

    it('UPDATE informa linhas afetadas', function () {
        DB::table('items')->insert(['sku' => 'A']);

        $r = DB::query('UPDATE items SET qty = :q WHERE sku = :s', ['q' => 3, 's' => 'A']);

        expect(succeeded($r))->toBeTrue();
        expect((int) meta($r, 'affected_rows'))->toBe(1);
    });

    it('aceita "?" posicional e placeholder repetido', function () {
        DB::table('items')->insert(['sku' => 'A']);

        expect(column(DB::query("\n SELECT sku FROM items WHERE sku = ?", ['A']), 'sku'))->toBe(['A']);
        expect(column(DB::query('SELECT sku FROM items WHERE sku = :s OR sku = :s', ['s' => 'A']), 'sku'))->toBe(['A']);
    });
});
