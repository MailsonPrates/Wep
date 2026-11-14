<?php

use Wep\DB\Query\Formatter;

describe('Formatter::hasNamedPlaceholders', function () {

    it('detecta placeholders nomeados', function (string $sql, bool $expected) {
        expect(Formatter::hasNamedPlaceholders($sql))->toBe($expected);
    })->with([
        'simples'              => ['SELECT * FROM t WHERE id = :id', true],
        'vários'               => ['WHERE a = :a AND b = :b_2', true],
        'sem placeholder'      => ['SELECT * FROM t', false],
        'horário em string'    => ["SELECT '10:30' AS hora", false],
        'cast com ::'          => ['SELECT x::int FROM t', false],
        'dois-pontos no meio'  => ["SELECT 'a:b'", false],
    ]);
});

describe('Formatter::interpolate (somente debug)', function () {

    it('troca placeholders nomeados e quota strings', function () {
        $sql = Formatter::interpolate('SELECT * FROM t WHERE name = :n AND age > :a', ['n' => "O'Brien", 'a' => 18]);

        expect($sql)->toBe("SELECT * FROM t WHERE name = 'O\\'Brien' AND age > 18");
    });

    it('aceita chaves com ":" no início', function () {
        expect(Formatter::interpolate('WHERE id = :id', [':id' => 7]))->toBe('WHERE id = 7');
    });

    it('troca "?" posicionais na ordem', function () {
        expect(Formatter::interpolate('WHERE a = ? AND b = ?', ['x', 2]))->toBe("WHERE a = 'x' AND b = 2");
    });

    it('representa null e bool', function () {
        expect(Formatter::interpolate('SET a = :a, b = :b, c = :c', ['a' => null, 'b' => true, 'c' => false]))
            ->toBe('SET a = NULL, b = 1, c = 0');
    });

    it('não confunde nomes que começam igual (:p1 / :p10)', function () {
        expect(Formatter::interpolate('IN(:p1,:p10)', ['p1' => 'a', 'p10' => 'b']))->toBe("IN('a','b')");
    });

    it('devolve a query intacta sem parâmetros', function () {
        expect(Formatter::interpolate('SELECT :x', []))->toBe('SELECT :x');
    });
});
