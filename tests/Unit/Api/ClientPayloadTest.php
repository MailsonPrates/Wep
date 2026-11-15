<?php

use Wep\Api\ClientPayload;
use Wep\Api\InvalidPayloadException;

function payload(array $data, string $op = 'get', array $options = []): array
{
    return ClientPayload::parse($data, $op, $options + ['table' => 'produtos', 'filters' => ['status']]);
}

function refuses(array $data, string $op = 'get', array $options = [], string $message = ''): void
{
    expect(fn () => payload($data, $op, $options))->toThrow(InvalidPayloadException::class, $message);
}

describe('formato antigo (o front montava a query)', function () {

    it('recusa todas as chaves de query, em qualquer operação', function () {
        foreach (ClientPayload::LEGACY_KEYS as $key) {
            foreach (['get', 'create', 'update', 'delete'] as $op) {
                refuses([$key => []], $op, [], 'não monta mais queries');
            }
        }
    });

    it('recusa o payload completo que o api-factory antigo enviava', function () {
        refuses(['fields' => [], 'where' => [['id', '=', 1]], 'or' => [], 'andOr' => [], 'orderBy' => [], 'limit' => [], 'page' => 1]);
    });
});

describe('get', function () {

    it('sem nada: lista', function () {
        expect(payload([]))->toBe(['filters' => []]);
    });

    it('com id: um registro', function () {
        expect(payload(['id' => 5]))->toBe(['id' => 5])
            ->and(payload(['id' => 'abc']))->toBe(['id' => 'abc']);
    });

    it('filtros só nas colunas declaradas', function () {
        expect(payload(['filters' => ['status' => 'on']]))->toBe(['filters' => ['status' => 'on']])
            ->and(payload(['filters' => ['status' => ['on', 'off']]]))->toBe(['filters' => ['status' => ['on', 'off']]]);

        refuses(['filters' => ['nome' => 'x']], 'get', [], 'Filtro não permitido');
        refuses(['filters' => ['password' => 'x']], 'get', [], 'Filtro não permitido');
        refuses(['filters' => ['status' => ['a' => 'b']]]);
        refuses(['filters' => ['status' => [['x']]]]);
        refuses(['filters' => ['on']]);
    });

    it('page e per_page inteiros >= 1, per_page com teto', function () {
        expect(payload(['page' => 2, 'per_page' => 10]))->toBe(['filters' => [], 'page' => 2, 'per_page' => 10])
            ->and(payload(['per_page' => 99999], 'get', ['max_limit' => 100])['per_page'])->toBe(100);

        refuses(['page' => 0]);
        refuses(['page' => 'x']);
        refuses(['per_page' => -1]);
    });

    it('ignora chaves que não são do contrato (custom da rota, query string...)', function () {
        expect(payload(['method' => 'update', 'role' => 'api.x', 'qualquer' => 1]))->toBe(['filters' => []]);
    });
});

describe('create / update / delete', function () {

    it('create: só fields', function () {
        expect(payload(['fields' => ['nome' => 'X'], 'role' => 'api.x'], 'create'))->toBe(['fields' => ['nome' => 'X']]);

        refuses([], 'create', [], '"fields"');
        refuses(['fields' => []], 'create');
        refuses(['fields' => ['X']], 'create');
        refuses(['nome' => 'X'], 'create', [], '"fields"'); // payload plano não é mais aceito
    });

    it('colunas ocultas, inválidas e valores não escalares são recusados', function () {
        refuses(['fields' => ['password' => 'x']], 'create', [], 'não permitida');
        refuses(['fields' => ['segredo' => 'x']], 'create', ['hidden' => ['segredo']]);
        refuses(['fields' => ['users.name' => 'x']], 'create');
        refuses(['fields' => ['nome; DROP' => 'x']], 'create');
        refuses(['fields' => ['nome' => ['a']]], 'create');
    });

    it('a chave primária não vem do cliente (salvo create com fillable)', function () {
        refuses(['fields' => ['id' => 9, 'nome' => 'X']], 'create', [], 'chave primária');
        refuses(['id' => 1, 'fields' => ['id' => 9]], 'update', [], 'chave primária');
        refuses(['id' => 1, 'fields' => ['user_group_id' => 9]], 'update', ['primary_key' => 'user_group_id']);

        expect(payload(['fields' => ['id' => 9]], 'create', ['fillable' => ['id']]))->toBe(['fields' => ['id' => 9]]);
    });

    it('fillable restringe o que pode ser gravado', function () {
        refuses(['id' => 1, 'fields' => ['status' => 'admin']], 'update', ['fillable' => ['nome']], 'não pode ser gravada');
    });

    it('update exige id e fields; delete exige id', function () {
        expect(payload(['id' => 3, 'fields' => ['nome' => 'X']], 'update'))->toBe(['id' => 3, 'fields' => ['nome' => 'X']])
            ->and(payload(['id' => '3'], 'delete'))->toBe(['id' => '3']);

        refuses(['fields' => ['nome' => 'X']], 'update', [], '"id"');
        refuses(['id' => 3], 'update', [], '"fields"');
        refuses([], 'delete', [], '"id"');
        refuses(['id' => ['1', '2']], 'delete');
        refuses(['id' => true], 'delete');
        refuses(['id' => ''], 'delete');
    });

    it('operação desconhecida e módulo sem tabela', function () {
        refuses([], 'drop');
        expect(fn () => ClientPayload::parse([], 'get', ['table' => '']))->toThrow(InvalidPayloadException::class);
    });
});

describe('filterRows', function () {

    it('remove colunas ocultas de listas e de uma linha', function () {
        $list = (object) ['error' => false, 'data' => [['id' => 1, 'Password' => 'h'], ['id' => 2]]];
        $one = (object) ['error' => false, 'data' => ['id' => 1, 'password' => 'h']];

        expect(ClientPayload::filterRows($list, ['password'])->data)->toBe([['id' => 1], ['id' => 2]])
            ->and(ClientPayload::filterRows($one, ['password'])->data)->toBe(['id' => 1]);
    });
});
