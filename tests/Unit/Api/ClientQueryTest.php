<?php

use Wep\Api\ClientQuery;
use Wep\Api\ClientQueryException;

function sanitize(array $payload, string $op = 'get', array $options = []): array
{
    $query = ClientQuery::sanitize($payload, $op, $options + ['table' => 'produtos']);
    unset($query[ClientQuery::MARKER]);

    return $query;
}

function rejects(array $payload, string $op = 'get', array $options = [], string $message = ''): void
{
    expect(fn () => sanitize($payload, $op, $options))->toThrow(ClientQueryException::class, $message);
}

describe('get', function () {

    it('aceita o payload que o api-factory envia', function () {
        $payload = ['fields' => ['id', 'nome'], 'where' => [['status', '=', 'on']], 'or' => [], 'andOr' => [],
            'orderBy' => [['nome'], 'desc'], 'limit' => [10], 'page' => 2];

        expect(sanitize($payload))->toBe([
            'fields' => ['id', 'nome'],
            'where' => [['status', '=', 'on']],
            'orderBy' => [['nome'], 'desc'],
            'limit' => [10],
            'page' => 2,
        ]);
    });

    it('descarta leftJoin, debug, raw, fetch e onDuplicateUpdate', function () {
        $query = sanitize(['leftJoin' => ['users', 'users.id', 'produtos.id'], 'debug' => true, 'raw' => true,
            'fetch' => 'obj', 'onDuplicateUpdate' => ['x']]);

        expect(array_keys($query))->toBe(['fields', 'limit']);
    });

    it('recusa colunas de outra tabela ou de outro banco', function () {
        rejects(['fields' => ['users.password']], 'get', [], 'outra tabela');
        rejects(['fields' => ['mysql.user.authentication_string']]);
        rejects(['where' => [['users.id', '=', 1]]]);
        rejects(['orderBy' => ['users.name']]);
    });

    it('aceita a própria tabela qualificada, alias, *, DISTINCT e agregação', function () {
        expect(sanitize(['fields' => ['produtos.id', 'produtos.*', '*', 'nome AS n', 'DISTINCT status', 'COUNT(id) total', 'max(preco)']])['fields'])
            ->toHaveCount(7);

        expect(sanitize(['fields' => ['p.id']], 'get', ['table' => 'produtos p'])['fields'])->toBe(['p.id']);
    });

    it('bloqueia colunas ocultas em fields, where e orderBy (password sempre oculta)', function () {
        rejects(['fields' => ['password']]);
        rejects(['fields' => ['COUNT(password)']]);
        rejects(['fields' => ['password AS p']]);
        rejects(['where' => [['password', 'like', 'a%']]]);   // extração "às cegas" por filtro
        rejects(['where' => ['segredo' => 'x']], 'get', ['hidden' => ['segredo']]);
        rejects(['orderBy' => ['segredo']], 'get', ['hidden' => ['segredo']]);
    });

    it('recusa expressões que não são colunas', function () {
        rejects(['fields' => ['(SELECT password FROM users)']]);
        rejects(['fields' => ['id; DROP TABLE x']]);
        rejects(['fields' => [['id']]]);
        rejects(['fields' => ['nome' => 'x']]);
    });

    it('limit com teto, inclusive quando o cliente não envia limit', function () {
        expect(sanitize([])['limit'])->toBe([ClientQuery::DEFAULT_MAX_LIMIT])
            ->and(sanitize(['limit' => []], 'get', ['max_limit' => 50])['limit'])->toBe([50])
            ->and(sanitize(['limit' => [5000]], 'get', ['max_limit' => 50])['limit'])->toBe([50])
            ->and(sanitize(['limit' => [20, 5000]], 'get', ['max_limit' => 50])['limit'])->toBe([20, 50])
            ->and(sanitize(['limit' => 7])['limit'])->toBe([7]);

        rejects(['limit' => ['1; DROP']]);
        rejects(['limit' => [-1]]);
    });

    it('page inteira >= 1', function () {
        expect(sanitize(['page' => 0])['page'])->toBe(1);
        rejects(['page' => 'x']);
    });

    it('formatos de condição: lista, condição única e objeto', function () {
        expect(sanitize(['where' => ['id', '=', 1]])['where'])->toBe([['id', '=', 1]])
            ->and(sanitize(['where' => ['status' => 'on', 'id' => [1, 2]]])['where'])->toBe(['status' => 'on', 'id' => [1, 2]]);

        rejects(['where' => [['id']]]);
        rejects(['where' => 'id = 1']);
    });
});

describe('create / update / delete', function () {

    it('create grava só colunas da tabela, fora das ocultas', function () {
        expect(sanitize(['fields' => ['nome' => 'X', 'preco' => 1]], 'create'))->toBe(['fields' => ['nome' => 'X', 'preco' => 1]]);

        rejects(['fields' => ['password' => 'x']], 'create');
        rejects(['fields' => ['users.name' => 'x']], 'create');
        rejects(['fields' => ['nome' => ['array']]], 'create');
        rejects(['fields' => []], 'create');
    });

    it('fillable restringe as colunas graváveis (mass assignment)', function () {
        $options = ['fillable' => ['nome']];

        expect(sanitize(['fields' => ['nome' => 'X']], 'create', $options))->toBe(['fields' => ['nome' => 'X']]);

        rejects(['fields' => ['nome' => 'X', 'status' => 'admin']], 'create', $options, 'não pode ser gravada');
    });

    it('payload plano (sem "fields") também é saneado', function () {
        expect(sanitize(['nome' => 'X', 'debug' => true], 'create'))->toBe(['fields' => ['nome' => 'X']]);
    });

    it('update exige where com igualdade', function () {
        expect(sanitize(['fields' => ['nome' => 'X'], 'where' => [['id', '=', 1]]], 'update'))
            ->toBe(['fields' => ['nome' => 'X'], 'where' => [['id', '=', 1]]])
            ->and(sanitize(['fields' => ['nome' => 'X'], 'where' => [['id', 'in', [1, 2]]]], 'update')['where'])->toBe([['id', 'in', [1, 2]]])
            ->and(sanitize(['fields' => ['nome' => 'X'], 'where' => [['id', 1]]], 'update')['where'])->toBe([['id', 1]]);

        rejects(['fields' => ['nome' => 'X']], 'update', [], 'sem where');
        rejects(['fields' => ['nome' => 'X'], 'where' => [['id', '>', 0]]], 'update', [], 'igualdade');
        rejects(['fields' => ['nome' => 'X'], 'where' => [['id', 'is not null']]], 'update', [], 'igualdade');
    });

    it('update/delete não aceitam or/andOr (ampliariam as linhas afetadas)', function () {
        rejects(['fields' => ['nome' => 'X'], 'where' => [['id', '=', 1]], 'or' => [['id', '>', 0]]], 'update');
        rejects(['where' => [['id', '=', 1]], 'andOr' => [['status', '=', 'on']]], 'delete');
    });

    it('allow_bulk libera operações em massa', function () {
        $options = ['allow_bulk' => true];

        expect(sanitize(['where' => [['preco', '<', 5]], 'or' => [['status', '=', 'off']]], 'delete', $options))
            ->toBe(['where' => [['preco', '<', 5]], 'or' => [['status', '=', 'off']]]);
    });

    it('delete por objeto ou payload plano', function () {
        expect(sanitize(['where' => ['id' => 3]], 'delete'))->toBe(['where' => ['id' => 3]])
            ->and(sanitize(['id' => 3], 'delete'))->toBe(['where' => [['id', '=', 3]]])
            ->and(sanitize(['id' => [3, 4]], 'delete'))->toBe(['where' => [['id', 'in', [3, 4]]]]);

        rejects([], 'delete', [], 'sem where');
    });

    it('update plano (sem where) é recusado, em vez de virar um where por todas as colunas', function () {
        rejects(['title' => 'x', 'id' => 3], 'update', [], 'sem where');
    });

    it('operação desconhecida e módulo sem tabela', function () {
        rejects([], 'drop');
        expect(fn () => ClientQuery::sanitize([], 'get', ['table' => '']))->toThrow(ClientQueryException::class);
    });
});

describe('filterRows', function () {

    it('remove colunas ocultas das linhas (SELECT *)', function () {
        $response = (object) ['error' => false, 'data' => [['id' => 1, 'Password' => 'h', 'segredo' => 's'], ['id' => 2]]];

        expect(ClientQuery::filterRows($response, ['password', 'segredo'])->data)->toBe([['id' => 1], ['id' => 2]]);
    });

    it('não mexe em respostas de erro', function () {
        $error = (object) ['error' => true, 'message' => 'x'];

        expect(ClientQuery::filterRows($error, ['password']))->toBe($error);
    });
});
