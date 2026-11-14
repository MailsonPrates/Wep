<?php

use Wep\DB\Query\Builders\Identifier;
use Wep\DB\Query\Raw;

describe('Identifier::quote', function () {

    it('escapa identificadores válidos com crase', function (string $input, string $expected) {
        expect(Identifier::quote($input))->toBe($expected);
    })->with([
        'coluna'            => ['id', '`id`'],
        'tabela.coluna'     => ['users.id', '`users`.`id`'],
        'schema.tabela.col' => ['db.users.id', '`db`.`users`.`id`'],
        'asterisco'         => ['*', '*'],
        'tabela.*'          => ['users.*', '`users`.*'],
        'com underscore'    => ['created_at', '`created_at`'],
        'com espaços nas pontas' => ['  name  ', '`name`'],
    ]);

    it('rejeita qualquer coisa que não seja um identificador', function (string $input) {
        expect(fn() => Identifier::quote($input))->toThrow(InvalidArgumentException::class);
    })->with([
        'ponto e vírgula' => ["id; DROP TABLE users"],
        'crase'           => ['id`'],
        'espaço no meio'  => ['a b'],
        'começa com número' => ['1abc'],
        'quatro partes'   => ['a.b.c.d'],
        'comentário'      => ['id--'],
        'subselect'       => ['(SELECT 1)'],
        'aspas'           => ["id'"],
        'vazio'           => [''],
    ]);

    it('rejeita valores que não são string', function () {
        expect(fn() => Identifier::quote(123))->toThrow(InvalidArgumentException::class);
        expect(fn() => Identifier::quote(null))->toThrow(InvalidArgumentException::class);
        expect(fn() => Identifier::quote(['id']))->toThrow(InvalidArgumentException::class);
    });

    it('deixa DB::raw() passar sem alteração', function () {
        expect(Identifier::quote(new Raw('LOWER(email)')))->toBe('LOWER(email)');
    });

    it('não ecoa conteúdo perigoso na mensagem de erro', function () {
        try {
            Identifier::quote("x'\n<script>");
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain('<script>');
            expect($e->getMessage())->not->toContain("\n");
        }
    });
});

describe('Identifier::table', function () {

    it('aceita alias opcional', function (string $input, string $expected) {
        expect(Identifier::table($input))->toBe($expected);
    })->with([
        'sem alias'   => ['users', '`users`'],
        'alias'       => ['users u', '`users` AS `u`'],
        'alias com AS' => ['users AS u', '`users` AS `u`'],
        'com schema'  => ['db.users', '`db`.`users`'],
    ]);

    it('rejeita injection', function () {
        expect(fn() => Identifier::table("users`; DROP TABLE users; -- "))->toThrow(InvalidArgumentException::class);
        expect(fn() => Identifier::table('orders ON 1=1 --'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Identifier::select', function () {

    it('monta campos, aliases e agregações permitidas', function (string $input, string $expected) {
        expect(Identifier::select($input))->toBe($expected);
    })->with([
        'coluna'            => ['name', '`name`'],
        'alias com AS'      => ['name AS n', '`name` AS `n`'],
        'alias sem AS'      => ['name n', '`name` AS `n`'],
        'count(*)'          => ['COUNT(*) total', 'COUNT(*) AS `total`'],
        'minúsculas'        => ['count(*) as total', 'COUNT(*) AS `total`'],
        'count distinct'    => ['count(distinct id)', 'COUNT(DISTINCT `id`)'],
        'sum qualificado'   => ['SUM(users.total) AS t', 'SUM(`users`.`total`) AS `t`'],
        'distinct'          => ['DISTINCT status', 'DISTINCT `status`'],
        'asterisco'         => ['*', '*'],
    ]);

    it('barra expressões livres e injection', function (string $input) {
        expect(fn() => Identifier::select($input))->toThrow(InvalidArgumentException::class, 'DB::raw()');
    })->with([
        'função não permitida' => ['IFNULL(opt, 0)'],
        'injection com FROM'   => ['id FROM users; DROP TABLE users; --'],
        'subselect'            => ['(SELECT password FROM admins)'],
    ]);

    it('aceita DB::raw()', function () {
        expect(Identifier::select(new Raw('IFNULL(opt, 0) AS o')))->toBe('IFNULL(opt, 0) AS o');
    });
});

describe('Identifier::order', function () {

    it('aceita coluna e RAND()', function () {
        expect(Identifier::order('name'))->toBe('`name`');
        expect(Identifier::order('users.name'))->toBe('`users`.`name`');
        expect(Identifier::order('rand()'))->toBe('RAND()');
        expect(Identifier::order('RAND( )'))->toBe('RAND()');
    });

    it('rejeita expressões', function () {
        expect(fn() => Identifier::order('id DESC'))->toThrow(InvalidArgumentException::class);
        expect(fn() => Identifier::order('SLEEP(5)'))->toThrow(InvalidArgumentException::class);
    });
});
