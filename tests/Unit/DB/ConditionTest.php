<?php

use App\Core\DB\Query\Builders\Condition;
use App\Core\DB\Query\Builders\Placeholders;

function cond(string $type, string $col, string $operator, $value): array
{
    return ['type' => $type, 'col' => $col, 'operator' => $operator, 'value' => $value];
}

describe('Placeholders', function () {

    it('gera nomes sequenciais e previsíveis', function () {
        $bag = new Placeholders();

        expect($bag->add('a'))->toBe(':p1');
        expect($bag->add('b'))->toBe(':p2');
        expect($bag->all())->toBe(['p1' => 'a', 'p2' => 'b']);
    });

    it('não repete nomes em listas grandes', function () {
        $bag = new Placeholders();

        foreach (range(1, 5000) as $n) $bag->add($n);

        expect($bag->all())->toHaveCount(5000);
    });
});

describe('Condition::buildQuery', function () {

    it('agrupa por tipo, com WHERE sempre primeiro', function () {
        $q = Condition::buildQuery([
            cond('where', 'a', '=', 1),
            cond('or',    'b', '=', 2),
            cond('where', 'c', '>', 3),
        ]);

        expect($q->string)->toBe('WHERE `a` = :p1 AND `c` > :p3 OR `b` = :p2');
        expect($q->placeholders)->toBe(['p1' => 1, 'p2' => 2, 'p3' => 3]);
    });

    it('monta orAnd e andOr entre parênteses', function () {
        $q = Condition::buildQuery([
            cond('where', 'a', '=', 1),
            cond('orAnd', 'b', '=', 2),
            cond('orAnd', 'c', '=', 3),
            cond('andOr', 'd', '=', 4),
            cond('andOr', 'e', '=', 5),
        ]);

        expect($q->string)->toBe('WHERE `a` = :p1 OR (`b` = :p2 AND `c` = :p3) AND (`d` = :p4 OR `e` = :p5)');
    });

    it('recusa or()/andOr() sem where()', function () {
        expect(fn() => Condition::buildQuery([cond('or', 'a', '=', 1)]))
            ->toThrow(InvalidArgumentException::class, 'where()');
    });

    it('recusa tipo de condição desconhecido', function () {
        expect(fn() => Condition::buildQuery([cond('xor', 'a', '=', 1)]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('compartilha o mesmo Placeholders com quem chamou (ex.: SET do UPDATE)', function () {
        $bag = new Placeholders();
        $bag->add('valor-do-set');

        $q = Condition::buildQuery([cond('where', 'id', '=', 9)], $bag);

        expect($q->string)->toBe('WHERE `id` = :p2');
        expect($bag->all())->toBe(['p1' => 'valor-do-set', 'p2' => 9]);
    });
});

describe('Operadores', function () {

    it('monta cada operador', function (string $operator, $value, string $sql, array $params) {
        $q = Condition::buildQuery([cond('where', 'col', $operator, $value)]);

        expect($q->string)->toBe('WHERE ' . $sql);
        expect($q->placeholders)->toBe($params);
    })->with([
        '='            => ['=', 5, '`col` = :p1', ['p1' => 5]],
        '!='           => ['!=', 5, '`col` != :p1', ['p1' => 5]],
        '>='           => ['>=', 5, '`col` >= :p1', ['p1' => 5]],
        'like'         => ['like', 'a%', '`col` LIKE :p1', ['p1' => 'a%']],
        'not like'     => ['not like', 'a%', '`col` NOT LIKE :p1', ['p1' => 'a%']],
        'in'           => ['in', [1, 2], '`col` IN(:p1,:p2)', ['p1' => 1, 'p2' => 2]],
        'not in'       => ['not in', [1, 2], '`col` NOT IN(:p1,:p2)', ['p1' => 1, 'p2' => 2]],
        'between'      => ['between', [1, 9], '`col` BETWEEN :p1 AND :p2', ['p1' => 1, 'p2' => 9]],
        'not between'  => ['not between', [1, 9], '`col` NOT BETWEEN :p1 AND :p2', ['p1' => 1, 'p2' => 9]],
        'is null'      => ['is null', '', '`col` IS NULL', []],
        'is not null'  => ['is not null', '', '`col` IS NOT NULL', []],
        'is not empty' => ['is not empty', '', "`col` <> ''", []],
    ]);

    it('IN vazio nunca casa e NOT IN vazio sempre casa (sem gerar SQL inválido)', function () {
        expect(Condition::buildQuery([cond('where', 'col', 'in', [])])->string)->toBe('WHERE 1 = 0');
        expect(Condition::buildQuery([cond('where', 'col', 'not in', [])])->string)->toBe('WHERE 1 = 1');
    });

    it('exige exatamente dois valores no between', function () {
        expect(fn() => Condition::buildQuery([cond('where', 'col', 'between', [1])]))->toThrow(InvalidArgumentException::class);
        expect(fn() => Condition::buildQuery([cond('where', 'col', 'between', [1, 2, 3])]))->toThrow(InvalidArgumentException::class);
        expect(fn() => Condition::buildQuery([cond('where', 'col', 'between', 'x')]))->toThrow(InvalidArgumentException::class);
    });

    it('rejeita operador fora da lista', function (string $operator) {
        expect(fn() => Condition::buildQuery([cond('where', 'col', $operator, 1)]))
            ->toThrow(InvalidArgumentException::class, 'Invalid operator');
    })->with(['banana', '= 1 OR 1=1 --', '; DROP TABLE users', '']);

    it('rejeita array onde só cabe valor escalar', function () {
        expect(fn() => Condition::buildQuery([cond('where', 'col', '>', [1, 2])]))->toThrow(InvalidArgumentException::class);
        expect(fn() => Condition::buildQuery([cond('where', 'col', 'like', [1])]))->toThrow(InvalidArgumentException::class);
    });

    it('nunca coloca o valor dentro do SQL', function () {
        $q = Condition::buildQuery([cond('where', 'col', '=', "x' OR '1'='1")]);

        expect($q->string)->not->toContain('OR');
        expect($q->placeholders)->toBe(['p1' => "x' OR '1'='1"]);
    });
});
