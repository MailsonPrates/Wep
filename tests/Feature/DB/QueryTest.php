<?php

use Wep\DB;

beforeEach(fn() => freshDatabase());
afterEach(fn() => DB::setConfig(['debug' => false, 'expose_errors' => false]));

describe('DB::query', function () {

    it('executa com placeholders nomeados', function () {
        $r = DB::query('SELECT id FROM users WHERE status = :s AND age > :a', ['s' => 'on', 'a' => 20]);

        expect(column($r))->toBe([1, 3]);
    });

    it('aceita chaves com ":" no início', function () {
        expect(column(DB::query('SELECT id FROM users WHERE id = :id', [':id' => 2])))->toBe([2]);
    });

    it('aceita "?" posicional', function () {
        expect(column(DB::query("SELECT id FROM users WHERE name = ?", ['Ana'])))->toBe([1]);
    });

    it('aceita o mesmo placeholder repetido', function () {
        // id = 5 (Davi) OU stock = 5 (Ana)
        $r = DB::query('SELECT id FROM users WHERE id = :a OR stock = :a ORDER BY id', ['a' => 5]);

        expect(column($r))->toBe([1, 5]);
    });

    it('executa sem parâmetros', function () {
        expect(rows(DB::query('SELECT COUNT(*) AS n FROM users'))[0]['n'])->toBe(5);
    });

    it('não confunde horário literal com placeholder (regressão)', function () {
        $r = DB::query("SELECT '10:30' AS hora");

        expect(succeeded($r))->toBeTrue();
        expect(rows($r))->toBe([['hora' => '10:30']]);
    });

    it('funciona com espaço e quebra de linha no início', function () {
        $r = DB::query("\n   SELECT id FROM users WHERE id = :id", ['id' => 1]);

        expect(column($r))->toBe([1]);
    });

    it('retorna erro quando faltam os valores dos placeholders', function () {
        $r = DB::query('SELECT id FROM users WHERE status = :s');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toBe('Missing placeholder values');
    });

    it('retorna erro com query vazia', function () {
        expect(errorOf(DB::query('')))->toBe('Invalid query string');
    });

    it('queries sem retorno de linhas informam last_id e affected_rows', function () {
        $r = DB::query('UPDATE users SET stock = :s WHERE status = :st', ['s' => 1, 'st' => 'on']);

        expect(succeeded($r))->toBeTrue();
        expect((int) meta($r, 'affected_rows'))->toBe(3);

        $i = DB::query('INSERT INTO users (name) VALUES (:n)', ['n' => 'Zed']);

        expect(succeeded($i))->toBeTrue();
        expect((int) meta($i, 'last_id'))->toBe(6);
    });

    it('rejeita parâmetro array', function () {
        $r = DB::query('SELECT id FROM users WHERE id = :id', ['id' => [1, 2]]);

        expect(succeeded($r))->toBeFalse();
    });
});

describe('erros do banco', function () {

    it('devolve mensagem genérica e não expõe detalhes do banco', function () {
        $r = DB::query('SELEC nope FROM segredo_interno');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toBe('Erro ao executar a consulta no banco de dados');
        expect(errorOf($r))->not->toContain('segredo_interno');
        expect(errorOf($r))->not->toContain('SQLSTATE');
    });

    it('expõe a mensagem original com expose_errors = true', function () {
        DB::setConfig(['expose_errors' => true]);

        $r = DB::query('SELEC nope');

        expect(errorOf($r))->toContain('syntax error');
    });

    it('violação de unique vira mensagem amigável no MySQL (ver tests/Integration)', function () {
        // no SQLite o código de erro do driver é outro; aqui só garantimos que não vaza detalhes
        DB::$pdo->exec('CREATE UNIQUE INDEX ux_email ON users (email)');

        $r = DB::table('users')->insert(['email' => 'ana@x.com']);

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->not->toContain('ux_email');
        expect(errorOf($r))->not->toContain('users.email');
    });
});

describe('modo debug', function () {

    it('DB::table(..., ["debug" => true]) monta a query sem abrir conexão', function () {
        DB::$pdo = null;   // se tentasse conectar, falharia (não há MySQL configurado)

        $q = DB::table('users', ['debug' => true])->where('age', '>', 3)->get('id');

        expect($q->string)->toBe('SELECT `id` FROM `users` WHERE `age` > :p1');
        expect($q->raw)->toBe('SELECT `id` FROM `users` WHERE `age` > 3');
        expect($q->placeholders)->toBe(['p1' => 3]);
    });

    it('não quebra as queries seguintes (regressão: antes sobrescrevia DB::$pdo)', function () {
        DB::table('users', ['debug' => true])->where('id', 1)->get('id');

        expect(DB::$pdo)->toBeInstanceOf(PDO::class);
        expect(column(DB::table('users')->where('id', 1)->get('id')))->toBe([1]);
    });

    it('->debug() mostra a query montada e não executa', function () {
        $q = DB::table('users')->debug()->where('id', 1)->update('status', 'x');

        expect($q->string)->toBe('UPDATE `users` SET `status` = :p1 WHERE `id` = :p2');
        expect($q->raw)->toBe("UPDATE `users` SET `status` = 'x' WHERE `id` = 1");
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
    });

    it('monta delete e joins', function () {
        $d = DB::table('users')->debug()->where('id', 1)->delete();
        $j = DB::table('users')->debug()->leftJoin('orders', 'user_id', 'users.id')->get('users.name');

        expect($d->string)->toBe('DELETE FROM `users` WHERE `id` = :p1');
        expect($j->string)->toBe('SELECT `users`.`name` FROM `users` LEFT JOIN `orders` ON `orders`.`user_id` = `users`.`id`');
    });

    it('DB::query em debug global devolve a query com valores', function () {
        DB::setConfig(['debug' => true]);

        $q = DB::query('SELECT * FROM users WHERE name = :n', ['n' => "O'Brien"]);

        expect($q->query_raw)->toBe("SELECT * FROM users WHERE name = 'O\\'Brien'");
        expect(userCount())->toBe(5);
    });

    it('os mesmos filtros geram sempre o mesmo SQL', function () {
        $a = DB::table('users')->debug()->where('a', 1)->where('b', 'in', [1, 2])->get('id')->string;
        $b = DB::table('users')->debug()->where('a', 9)->where('b', 'in', [7, 8])->get('id')->string;

        expect($a)->toBe($b);
    });
});
