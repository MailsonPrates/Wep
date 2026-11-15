<?php

use Wep\DB;

beforeEach(fn() => freshDatabase());

describe('SQL injection por nomes de colunas/tabelas', function () {

    it('barra a tentativa e não altera o banco: :name', function (string $name) {
        $r = attack($name);

        expect(succeeded($r))->toBeFalse();

        // a tabela continua lá, com os mesmos dados
        expect(userCount())->toBe(5);
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
        expect((int) DB::$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn())->toBe(4);
    })->with([
        'orderBy com ;',
        'orderBy com subselect',
        'orderBy com direção maliciosa',
        'where com coluna maliciosa',
        'campos do select',
        'limit',
        'nome da tabela',
        'tabela do join',
        'ON do join',
        'coluna do update',
        'coluna do insert',
        'operador inválido',
        'between sem dois valores',
        'modo de fetch inválido',
        'valor array em operador escalar',
    ]);

    it('nunca chega a executar nada: o SQL malicioso nem é montado', function (string $name) {
        $r = attack($name);

        // erro de validação (mensagem nossa), e não erro do banco
        expect(errorOf($r))->not->toContain('SQLSTATE');
    })->with([
        'orderBy com ;',
        'where com coluna maliciosa',
        'campos do select',
        'coluna do update',
    ]);
});

describe('valores de usuário', function () {

    it('sempre vão por bind', function (string $payload) {
        $q = DB::table('users')->debug()->where('name', $payload)->get('id');

        expect($q->string)->toBe('SELECT `id` FROM `users` WHERE `name` = :p1');
        expect($q->placeholders)->toBe(['p1' => $payload]);
        expect(rows(DB::table('users')->where('name', $payload)->get('id')))->toBe([]);
        expect(userCount())->toBe(5);
    })->with([
        'aspas'        => ["x' OR '1'='1"],
        'stacked'      => ["x'; DROP TABLE users; --"],
        'comentário'   => ['x/*'],
        'barra'        => ['\\'],
        'null byte'    => ["x\0y"],
    ]);

    it('IN com valores maliciosos continua bindado', function () {
        $q = DB::table('users')->debug()->where('id', 'in', ["1) OR (1=1", 2])->get('id');

        expect($q->string)->toBe('SELECT `id` FROM `users` WHERE `id` IN(:p1,:p2)');
    });

    it('LIKE com valor malicioso continua bindado', function () {
        $q = DB::table('users')->debug()->where('name', 'like', "%' OR 1=1 --")->get('id');

        expect($q->string)->toBe('SELECT `id` FROM `users` WHERE `name` LIKE :p1');
    });
});

describe('guardas de update/delete', function () {

    it('nenhuma combinação sem where consegue atingir a tabela toda', function () {
        $attempts = [
            fn() => DB::table('users')->update('status', 'x'),
            fn() => DB::table('users')->or('id', 1)->update('status', 'x'),
            fn() => DB::table('users')->andOr([['id', 1], ['id', 2]])->update('status', 'x'),
            fn() => DB::table('users')->delete(),
            fn() => DB::table('users')->or('id', 1)->delete(),
        ];

        foreach ($attempts as $attempt) {
            expect(succeeded($attempt()))->toBeFalse();
        }

        expect(userCount())->toBe(5);
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
    });
});

describe('DB::raw()', function () {

    it('é a única forma de passar SQL livre (e o conteúdo é de responsabilidade de quem chama)', function () {
        $q = DB::table('users')->debug()->orderBy(DB::raw('FIELD(id, 3, 1)'))->get('id');

        expect($q->string)->toContain('ORDER BY FIELD(id, 3, 1) ASC');
    });
});

describe('processo', function () {

    it('operador inválido não derruba o processo (antes executava exit())', function () {
        $r = DB::table('users')->where('id', 'banana', 5)->get('id');

        expect(errorOf($r))->toContain('Invalid operator');
    });
});
