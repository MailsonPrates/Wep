<?php

use App\Modules\Produto\Produto;
use Wep\Router\Http\RouterHttpRequest;

beforeEach(fn () => crudDatabase());

afterEach(function () {
    RouterHttpRequest::setCurrent(null);
    resetRequest();
});

function ids(array $response): array
{
    return array_column($response['data'] ?? [], 'id');
}

function count_rows(string $where = '1 = 1'): int
{
    return (int) crudDatabase(false)->query("SELECT COUNT(*) FROM produtos WHERE $where")->fetchColumn();
}

describe('CRUD automático: contrato por id', function () {

    it('get(): lista com ordem e teto da config (order_by id DESC, max_limit 3)', function () {
        expect(ids(crudCall('get', [])))->toBe([5, 4, 3]);
    });

    it('get(id): um registro, sem colunas ocultas', function () {
        $r = crudCall('get', ['id' => 2]);

        expect($r['data'])->toBe(['id' => 2, 'nome' => 'Caderno', 'preco' => 15, 'status' => 'on']);
    });

    it('get(id) inexistente: 404', function () {
        expect(crudCall('get', ['id' => 999]))->toMatchArray(['error' => true, 'http_code' => 404]);
    });

    it('get({filtro}) com paginação (per_page 2)', function () {
        $r = crudCall('get', ['filters' => ['status' => 'on'], 'page' => 1]);

        expect(ids($r))->toBe([4, 2])
            ->and($r['page'])->toBe(1)
            ->and($r['per_page'])->toBe(2)
            ->and(ids(crudCall('get', ['filters' => ['status' => 'on'], 'page' => 2])))->toBe([1])
            ->and(ids(crudCall('get', ['filters' => ['status' => ['off']]])))->toBe([5, 3]);
    });

    it('filtro não declarado: 400', function () {
        expect(crudCall('get', ['filters' => ['nome' => 'Caneta']]))->toMatchArray(['error' => true, 'http_code' => 400]);
    });

    it('create, update(id) e delete(id)', function () {
        expect(crudCall('create', ['fields' => ['nome' => 'Cola', 'preco' => 4]])['error'])->toBeFalse()
            ->and(count_rows("nome = 'Cola'"))->toBe(1);

        $id = (int) crudDatabase(false)->query("SELECT id FROM produtos WHERE nome = 'Cola'")->fetchColumn();

        expect(crudCall('update', ['id' => $id, 'fields' => ['preco' => 5]])['error'])->toBeFalse()
            ->and(count_rows("id = $id AND preco = 5"))->toBe(1)
            ->and(crudCall('delete', ['id' => $id])['error'])->toBeFalse()
            ->and(count_rows())->toBe(5);
    });

    it('update afeta só o registro do id', function () {
        crudCall('update', ['id' => 1, 'fields' => ['status' => 'x']]);

        expect(count_rows("status = 'x'"))->toBe(1);
    });
});

describe('o cliente não monta queries', function () {

    it('ATAQUE: leftJoin para ler hashes de senha', function () {
        $r = crudCall('get', ['fields' => ['users.password'], 'leftJoin' => ['users', 'users.id', 'produtos.id']]);

        expect($r)->toMatchArray(['error' => true, 'http_code' => 400])
            ->and(json_encode($r))->not->toContain('HASHDOADMIN');
    });

    it('ATAQUE: where em massa no update e no delete', function () {
        crudCall('update', ['fields' => ['status' => 'hackeado'], 'where' => [['id', '>', 0]]]);
        crudCall('delete', ['where' => [['id', '>', 0]]]);

        expect(count_rows("status = 'hackeado'"))->toBe(0)
            ->and(count_rows())->toBe(5);
    });

    it('ATAQUE: debug, orderBy e limit', function () {
        foreach ([['debug' => true], ['orderBy' => ['RAND()']], ['limit' => [100000]]] as $payload) {
            expect(crudCall('get', $payload))->toMatchArray(['error' => true, 'http_code' => 400]);
        }
    });

    it('ATAQUE: gravar coluna oculta ou trocar a chave primária', function () {
        expect(crudCall('update', ['id' => 1, 'fields' => ['password' => 'x']])['error'])->toBeTrue()
            ->and(crudCall('update', ['id' => 1, 'fields' => ['id' => 99]])['error'])->toBeTrue()
            ->and(count_rows("id = 99"))->toBe(0);
    });
});

describe('métodos sobrescritos e código do servidor', function () {

    it('repassar o payload bruto ao Model é recusado (antigo X::update($request->data()))', function () {
        $request = clientRequest(['fields' => ['status' => 'x'], 'where' => [['id', '>', 0]]]);

        $r = Produto::update($request->data());

        expect($r->error)->toBeTrue()
            ->and($r->http_code)->toBe(400)
            ->and($r->message)->toContain('fromClient')
            ->and(count_rows("status = 'x'"))->toBe(0);
    });

    it('fromClient() usa o request atual por padrão', function () {
        clientRequest(['id' => 3, 'fields' => ['nome' => 'Mochila 2']]);

        expect(Produto::fromClient('update')->error)->toBeFalse()
            ->and(count_rows("nome = 'Mochila 2'"))->toBe(1);
    });

    it('clientPayload() valida sem executar, para complementar no servidor', function () {
        clientRequest(['fields' => ['nome' => 'Cola']]);

        $payload = Produto::clientPayload('create');
        $payload['fields']['status'] = 'revisar'; // valor do servidor

        expect(Produto::create(['fields' => $payload['fields']])->error)->toBeFalse()
            ->and(count_rows("nome = 'Cola' AND status = 'revisar'"))->toBe(1);
    });

    it('find/list/updateById/deleteById para o servidor', function () {
        expect(Produto::find(1)->data['nome'])->toBe('Caneta')
            ->and(Produto::find(999)->data)->toBeNull()
            ->and(array_column(Produto::list(['status' => 'off'])->data, 'id'))->toBe([5, 3])
            ->and(Produto::updateById(2, ['preco' => 20])->error)->toBeFalse()
            ->and(count_rows('id = 2 AND preco = 20'))->toBe(1)
            ->and(Produto::deleteById(5)->error)->toBeFalse()
            ->and(count_rows())->toBe(4);
    });

    it('o servidor continua com o query builder completo, inclusive leftJoin', function () {
        clientRequest(['qualquer' => 'coisa']);

        $r = Produto::get([
            'fields' => ['produtos.id', 'users.name'],
            'leftJoin' => ['users', 'users.id', 'produtos.id'],
            'where' => [['produtos.id', '=', 1]],
        ]);

        expect($r->data)->toBe([['id' => 1, 'name' => 'admin']]);
    });
});
