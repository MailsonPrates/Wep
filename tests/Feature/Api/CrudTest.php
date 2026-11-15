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

describe('CRUD automático (Controller → ClientQuery → Model)', function () {

    it('get normal do front funciona e respeita max_limit (3)', function () {
        $r = crudCall('get', ['fields' => [], 'where' => [['status', '=', 'on']], 'orderBy' => ['id'], 'limit' => [], 'page' => 1]);

        expect($r['error'])->toBeFalse()
            ->and(ids($r))->toBe([1, 2, 4]);

        expect(ids(crudCall('get', [])))->toHaveCount(3);
    });

    it('SELECT * não devolve colunas ocultas (password sempre, segredo pela config)', function () {
        $row = crudCall('get', ['where' => [['id', '=', 1]]])['data'][0];

        expect($row)->toHaveKeys(['id', 'nome', 'preco', 'status'])
            ->and($row)->not->toHaveKey('password')
            ->and($row)->not->toHaveKey('segredo');
    });

    it('ATAQUE: leftJoin com users para ler hashes de senha é recusado', function () {
        $r = crudCall('get', ['fields' => ['users.name', 'users.password'], 'leftJoin' => ['users', 'users.id', 'produtos.id']]);

        expect($r['error'])->toBeTrue()
            ->and($r['http_code'])->toBe(400)
            ->and(json_encode($r))->not->toContain('HASHDOADMIN');
    });

    it('ATAQUE: leftJoin sem fields é descartado (consulta só na própria tabela)', function () {
        $r = crudCall('get', ['leftJoin' => ['users', 'users.id', 'produtos.id'], 'where' => [['id', '=', 1]]]);

        expect($r['error'])->toBeFalse()
            ->and(array_keys($r['data'][0]))->not->toContain('name');
    });

    it('ATAQUE: debug não devolve a query', function () {
        $r = crudCall('get', ['debug' => true, 'where' => [['id', '=', 1]]]);

        expect($r)->not->toHaveKey('string')
            ->and(ids($r))->toBe([1]);
    });

    it('ATAQUE: update em massa (where id > 0) é recusado e nada muda', function () {
        $r = crudCall('update', ['fields' => ['status' => 'hackeado'], 'where' => [['id', '>', 0]]]);

        expect($r['error'])->toBeTrue()
            ->and(crudDatabase(false)->query("SELECT COUNT(*) FROM produtos WHERE status = 'hackeado'")->fetchColumn())->toBe(0);
    });

    it('ATAQUE: delete em massa é recusado', function () {
        crudCall('delete', ['where' => [['id', '>', 0]]]);

        expect((int) crudDatabase(false)->query('SELECT COUNT(*) FROM produtos')->fetchColumn())->toBe(5);
    });

    it('ATAQUE: gravar coluna oculta é recusado', function () {
        $r = crudCall('update', ['fields' => ['password' => 'x'], 'where' => [['id', '=', 1]]]);

        expect($r['error'])->toBeTrue()
            ->and(crudDatabase(false)->query('SELECT password FROM produtos WHERE id = 1')->fetchColumn())->toBe('hash-produto-1');
    });

    it('create, update e delete legítimos funcionam', function () {
        expect(crudCall('create', ['fields' => ['id' => 10, 'nome' => 'Cola', 'preco' => 4]])['error'])->toBeFalse()
            ->and(crudCall('update', ['fields' => ['preco' => 5], 'where' => [['id', '=', 10]]])['error'])->toBeFalse()
            ->and(crudDatabase(false)->query('SELECT preco FROM produtos WHERE id = 10')->fetchColumn())->toEqual(5)
            ->and(crudCall('delete', ['where' => [['id', '=', 10]]])['error'])->toBeFalse()
            ->and((int) crudDatabase(false)->query('SELECT COUNT(*) FROM produtos')->fetchColumn())->toBe(5);
    });
});

describe('proteção automática dos apps antigos', function () {

    it('Model::get($request->data()) inalterado é saneado (o jeito que o template faz)', function () {
        $request = clientRequest(['fields' => ['users.password'], 'leftJoin' => ['users', 'users.id', 'produtos.id']]);

        $r = Produto::get($request->data());

        expect($r->error)->toBeTrue()
            ->and($r->http_code)->toBe(400);
    });

    it('Model::update($request->data()) em massa é recusado', function () {
        $request = clientRequest(['fields' => ['status' => 'x'], 'where' => [['id', '>', 0]]]);

        expect(Produto::update($request->data())->error)->toBeTrue()
            ->and(crudDatabase(false)->query("SELECT COUNT(*) FROM produtos WHERE status = 'x'")->fetchColumn())->toBe(0);
    });

    it('Model::get($request->data()) remove colunas ocultas do resultado', function () {
        $request = clientRequest(['where' => [['id', '=', 2]]]);

        expect(Produto::get($request->data())->data[0])->not->toHaveKey('password');
    });

    it('chamada do servidor (array montado no código) continua com acesso total, inclusive leftJoin', function () {
        clientRequest(['qualquer' => 'coisa']);

        $r = Produto::get([
            'fields' => ['produtos.id', 'users.name'],
            'leftJoin' => ['users', 'users.id', 'produtos.id'],
            'where' => [['produtos.id', '=', 1]],
        ]);

        expect($r->error)->toBeFalse()
            ->and($r->data)->toBe([['id' => 1, 'name' => 'admin']]);
    });

    it('clientQuery() explícito, para quem altera o payload antes de gravar', function () {
        $request = clientRequest(['fields' => ['nome' => 'Novo'], 'where' => [['id', '=', 3]]]);

        $data = Produto::clientQuery($request->data(), 'update');
        $data['fields']['status'] = 'revisado'; // valor do servidor

        expect(Produto::update($data)->error)->toBeFalse()
            ->and(crudDatabase(false)->query('SELECT nome || status FROM produtos WHERE id = 3')->fetchColumn())->toBe('Novorevisado');
    });

    it('fora de uma requisição (CLI, jobs) não há saneamento automático', function () {
        RouterHttpRequest::setCurrent(null);

        expect(Produto::get(['fields' => ['password'], 'where' => [['id', '=', 1]]])->data[0]['password'])->toBe('hash-produto-1');
    });
});
