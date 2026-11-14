<?php

use App\Core\DB;

beforeEach(fn() => freshDatabase());

describe('campos', function () {

    it('seleciona tudo por padrão', function () {
        $r = DB::table('users')->get();

        expect(succeeded($r))->toBeTrue();
        expect(rows($r))->toHaveCount(5);
        expect(array_keys(rows($r)[0]))->toBe(['id', 'name', 'email', 'age', 'stock', 'status', 'note']);
    });

    it('aceita campos como argumentos, como array e via select()', function () {
        expect(array_keys(rows(DB::table('users')->get('id', 'name'))[0]))->toBe(['id', 'name']);
        expect(array_keys(rows(DB::table('users')->get(['id', 'name']))[0]))->toBe(['id', 'name']);
        expect(array_keys(rows(DB::table('users')->select('id'))[0]))->toBe(['id']);
    });

    it('aceita alias e agregações', function () {
        $r = DB::table('users')->get('COUNT(*) AS total', 'MAX(age) maior');

        expect(rows($r)[0])->toEqual(['total' => 5, 'maior' => 60]);
    });

    it('aceita DISTINCT', function () {
        $r = DB::table('users')->orderBy('status')->get('DISTINCT status');

        expect(column($r, 'status'))->toBe(['off', 'on']);
    });

    it('barra expressões SQL sem DB::raw()', function () {
        $r = DB::table('users')->get('UPPER(name)');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toContain('DB::raw()');
    });

    it('aceita expressões via DB::raw()', function () {
        $r = DB::table('users')->orderBy('id')->limit(1)->get(DB::raw('UPPER(name) AS n'));

        expect(rows($r))->toEqual([['n' => 'ANA']]);
    });

    it('aceita DB::raw() no orderBy', function () {
        $r = DB::table('users')->orderBy(DB::raw('LENGTH(name)'), 'DESC')->limit(1)->get('id');

        expect(column($r))->toBe([3]); // O'Brien é o nome mais longo
    });
});

describe('where', function () {

    it('usa "=" quando o operador é omitido', function () {
        expect(column(DB::table('users')->where('status', 'on')->get('id')))->toBe([1, 3, 4]);
    });

    it('aceita operadores de comparação', function (string $op, $value, array $expected) {
        expect(column(DB::table('users')->where('age', $op, $value)->get('id')))->toBe($expected);
    })->with([
        '='  => ['=', 30, [1]],
        '>'  => ['>', 26, [1, 3, 5]],
        '>=' => ['>=', 41, [3, 5]],
        '<'  => ['<', 25, [4]],
        '<=' => ['<=', 25, [2, 4]],
        '!=' => ['!=', 30, [2, 3, 4, 5]],
    ]);

    it('encadeia vários where com AND', function () {
        $r = DB::table('users')->where('age', '>=', 25)->where('age', '<=', 41)->get('id');

        expect(column($r))->toBe([1, 2, 3]);
    });

    it('aceita in / not in', function () {
        expect(column(DB::table('users')->where('id', 'in', [1, 3, 5])->get('id')))->toBe([1, 3, 5]);
        expect(column(DB::table('users')->where('id', 'not in', [1, 3, 5])->get('id')))->toBe([2, 4]);
    });

    it('IN vazio não retorna nada e NOT IN vazio retorna tudo', function () {
        expect(rows(DB::table('users')->where('id', 'in', [])->get('id')))->toBe([]);
        expect(column(DB::table('users')->where('id', 'not in', [])->get('id')))->toBe([1, 2, 3, 4, 5]);
    });

    it('aceita like / not like', function () {
        expect(column(DB::table('users')->where('name', 'like', '%r%')->get('id')))->toBe([2, 3, 4]);
        expect(column(DB::table('users')->where('name', 'not like', '%r%')->get('id')))->toBe([1, 5]);
    });

    it('aceita between / not between', function () {
        expect(column(DB::table('users')->where('age', 'between', [20, 45])->get('id')))->toBe([1, 2, 3]);
        expect(column(DB::table('users')->where('age', 'not between', [20, 45])->get('id')))->toBe([4, 5]);
    });

    it('aceita is null / is not null / is not empty', function () {
        expect(column(DB::table('users')->where('note', 'is null')->get('id')))->toBe([1, 2, 5]);
        expect(column(DB::table('users')->where('note', 'is not null')->get('id')))->toBe([3, 4]);
        expect(column(DB::table('users')->where('note', 'is not empty')->get('id')))->toBe([3]);
    });

    it('aceita lista de condições', function () {
        $r = DB::table('users')->where([['status', 'on'], ['age', '>', 20]])->get('id');

        expect(column($r))->toBe([1, 3]);
    });

    it('aceita array associativo ["coluna" => valor]', function () {
        expect(column(DB::table('users')->where(['status' => 'on'])->get('id')))->toBe([1, 3, 4]);
        expect(column(DB::table('users')->where(['status' => 'on', 'age' => 30])->get('id')))->toBe([1]);
    });

    it('trata array no valor como IN', function () {
        expect(column(DB::table('users')->where('id', [1, 2])->get('id')))->toBe([1, 2]);
        expect(column(DB::table('users')->where(['id' => [4, 5]])->get('id')))->toBe([4, 5]);
    });

    describe('valores "falsy" (regressão: antes eram confundidos com operador omitido)', function () {

        it('where("stock", ">", 0)', function () {
            expect(column(DB::table('users')->where('stock', '>', 0)->get('id')))->toBe([1, 3, 4, 5]);
        });

        it('where("stock", "=", 0) e where("stock", 0)', function () {
            expect(column(DB::table('users')->where('stock', '=', 0)->get('id')))->toBe([2]);
            expect(column(DB::table('users')->where('stock', 0)->get('id')))->toBe([2]);
        });

        it('where("note", "!=", "")', function () {
            expect(column(DB::table('users')->where('note', '!=', '')->get('id')))->toBe([3]);
        });
    });

    it('trata valores como dado, nunca como SQL', function () {
        expect(rows(DB::table('users')->where('name', "x' OR '1'='1")->get('id')))->toBe([]);
        expect(rows(DB::table('users')->where('name', "O'Brien")->get('email')))->toEqual([['email' => 'ob@x.com']]);
    });
});

describe('or / andOr', function () {

    it('or() simples', function () {
        $r = DB::table('users')->where('status', 'off')->or('age', '>', 50)->get('id');

        expect(column($r))->toBe([2, 5]);
    });

    it('or() em lote vira OR (a AND b)', function () {
        $r = DB::table('users')->where('status', 'on')->or([['age', 60], ['stock', 7]])->get('id');

        expect(column($r))->toBe([1, 3, 4, 5]);
    });

    it('andOr() vira AND (a OR b)', function () {
        $r = DB::table('users')->where('status', 'on')->andOr([['age', 19], ['age', 41]])->get('id');

        expect(column($r))->toBe([3, 4]);
    });

    it('or() sem where() é erro', function () {
        $r = DB::table('users')->or('id', 1)->get('id');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toContain('where()');
    });
});

describe('orderBy / limit / page', function () {

    it('ordena asc, desc e por várias colunas', function () {
        expect(column(DB::table('users')->orderBy('age')->get('id')))->toBe([4, 2, 1, 3, 5]);
        expect(column(DB::table('users')->orderBy('age', 'DESC')->get('id')))->toBe([5, 3, 1, 2, 4]);
        expect(column(DB::table('users')->orderBy(['status', 'age'], 'ASC')->get('id')))->toBe([2, 5, 4, 1, 3]);
    });

    it('mantém o comportamento legado: direção só vale para a última coluna', function () {
        // ORDER BY status, age DESC
        expect(column(DB::table('users')->orderBy('status', 'age', 'desc')->get('id')))->toBe([5, 2, 3, 1, 4]);
    });

    it('aplica limit em suas três formas', function () {
        expect(column(DB::table('users')->orderBy('id')->limit(2)->get('id')))->toBe([1, 2]);
        expect(column(DB::table('users')->orderBy('id')->limit(1, 2)->get('id')))->toBe([2, 3]);
        expect(column(DB::table('users')->orderBy('id')->limit([1, 2])->get('id')))->toBe([2, 3]);
    });

    it('pagina com limit + page', function () {
        expect(column(DB::table('users')->orderBy('id')->limit(2)->page(2)->get('id')))->toBe([3, 4]);
        expect(column(DB::table('users')->orderBy('id')->limit(2)->page(3)->get('id')))->toBe([5]);
    });

    it('trata page(0) e páginas negativas como a primeira', function () {
        expect(column(DB::table('users')->orderBy('id')->limit(2)->page(0)->get('id')))->toBe([1, 2]);
        expect(column(DB::table('users')->orderBy('id')->limit(2)->page(-4)->get('id')))->toBe([1, 2]);
    });

    it('converte page para inteiro (valor malicioso vira página 1)', function () {
        $r = DB::table('users')->orderBy('id')->limit(2)->page('1; DROP TABLE users')->get('id');

        expect(column($r))->toBe([1, 2]);
        expect(userCount())->toBe(5);
    });

    it('rejeita limit que não seja inteiro >= 0', function (...$limit) {
        $r = DB::table('users')->limit(...$limit)->get('id');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toContain('Invalid limit');
    })->with([
        'texto'     => ['abc'],
        'injection' => ['1; DROP TABLE users'],
        'negativo'  => [-1],
        'decimal'   => [1.5],
    ]);
});

describe('joins', function () {

    it('leftJoin simples', function () {
        $r = DB::table('users')
            ->leftJoin('orders', 'user_id', 'users.id')
            ->where('orders.total', '>', 50)
            ->orderBy('users.id')
            ->get('users.name', 'orders.total');

        expect(rows($r))->toEqual([['name' => 'Ana', 'total' => 100.5], ['name' => 'Bruno', 'total' => 55]]);
    });

    it('mantém usuários sem pedido (LEFT JOIN)', function () {
        $r = DB::table('users')->leftJoin('orders', 'user_id', 'users.id')->where('users.id', 5)->get('users.name', 'orders.number');

        expect(rows($r))->toEqual([['name' => 'Davi', 'number' => null]]);
    });

    it('aceita as formas associativa e em lista', function () {
        $assoc = DB::table('users')->leftJoin(['orders' => ['orders.user_id', 'users.id']])->where('orders.number', 'A2')->get('users.name');
        $list  = DB::table('users')->leftJoin([['orders', 'orders.user_id', 'users.id']])->where('orders.number', 'A2')->get('users.name');

        expect(column($assoc, 'name'))->toBe(['Ana']);
        expect(column($list, 'name'))->toBe(['Ana']);
    });

    it('usa todas as condições ON quando há várias', function () {
        // pedido só casa se user_id = users.id E orders.id = users.id  =>  Ana/A1 e Carla/C1
        $expected = [['name' => 'Ana', 'number' => 'A1'], ['name' => 'Carla', 'number' => 'C1']];

        $positional = DB::table('users')
            ->leftJoin('orders', ['orders.user_id', 'users.id'], ['orders.id', 'users.id'])
            ->where('orders.number', 'is not null')->orderBy('users.id')->get('users.name', 'orders.number');

        // regressão: na forma associativa a 1ª condição ON era descartada
        $assoc = DB::table('users')
            ->leftJoin(['orders' => [['orders.user_id', 'users.id'], ['orders.id', 'users.id']]])
            ->where('orders.number', 'is not null')->orderBy('users.id')->get('users.name', 'orders.number');

        expect(rows($positional))->toEqual($expected);
        expect(rows($assoc))->toEqual($expected);
    });

    it('aceita alias de tabela e o usa no campo sem prefixo', function () {
        $r = DB::table('users')
            ->leftJoin('orders o', 'user_id', 'users.id')
            ->where('o.total', '>', 50)->orderBy('users.id')->get('users.name');

        expect(column($r, 'name'))->toBe(['Ana', 'Bruno']);
    });

    it('funciona com tabela de nome reservado (regressão)', function () {
        DB::$pdo->exec('CREATE TABLE `order` (id INTEGER PRIMARY KEY, user_id INT)');
        DB::$pdo->exec('INSERT INTO `order` (user_id) VALUES (1)');

        $r = DB::table('users')->leftJoin('order', 'user_id', 'users.id')->where('order.id', 1)->get('users.name');

        expect(succeeded($r))->toBeTrue();
        expect(column($r, 'name'))->toBe(['Ana']);
    });
});

describe('formato do resultado (fetch)', function () {

    it('assoc por padrão', function () {
        expect(rows(DB::table('users')->where('id', 1)->get('name')))->toBe([['name' => 'Ana']]);
    });

    it('obj devolve a primeira linha como objeto (comportamento legado)', function () {
        $r = DB::table('users')->where('id', 1)->fetch('obj')->get('name');

        expect(rows($r))->toBeObject();
        expect(rows($r)->name)->toBe('Ana');
    });

    it('obj sem linhas devolve lista vazia', function () {
        expect(rows(DB::table('users')->where('id', 99)->fetch('obj')->get('name')))->toBe([]);
    });

    it('aceita column', function () {
        expect(rows(DB::table('users')->orderBy('id')->fetch('column')->get('name')))->toBe(['Ana', 'Bruno', "O'Brien", 'Carla', 'Davi']);
    });

    it('rejeita modo desconhecido sem derrubar nada', function () {
        $r = DB::table('users')->fetch('nao_existe')->get('id');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toBe('Invalid fetch mode');
    });

    it('raw devolve o statement junto', function () {
        $r = DB::table('users')->raw()->get('id');

        expect(meta($r, 'stmt'))->toBeInstanceOf(PDOStatement::class);
    });
});

describe('modo verboso', function () {

    it('get() com fields, where, orderBy e limit', function () {
        $r = DB::table('users')->get([
            'fields'  => ['id', 'name'],
            'where'   => ['status', 'on'],
            'orderBy' => ['age', 'DESC'],
            'limit'   => 2,
        ]);

        expect(column($r))->toBe([3, 1]);
        expect(array_keys(rows($r)[0]))->toBe(['id', 'name']);
    });

    it('where verboso em lista', function () {
        $r = DB::table('users')->get(['fields' => ['id'], 'where' => [['status', 'on'], ['age', '>', 20]], 'orderBy' => 'id']);

        expect(column($r))->toBe([1, 3]);
    });

    it('where verboso associativo', function () {
        $r = DB::table('users')->get(['fields' => ['id'], 'where' => ['status' => 'off'], 'orderBy' => 'id']);

        expect(column($r))->toBe([2, 5]);
    });

    it('leftJoin verboso', function () {
        $r = DB::table('users')->get([
            'fields'   => ['users.name', 'orders.total'],
            'leftJoin' => ['orders', 'user_id', 'users.id'],
            'where'    => ['orders.total', '>', 50],
            'orderBy'  => 'users.id',
        ]);

        expect(column($r, 'name'))->toBe(['Ana', 'Bruno']);
    });
});

describe('estado do handler', function () {

    it('não vaza condições de uma consulta para a próxima', function () {
        $handler = DB::table('users');

        expect(column($handler->where('id', 1)->get('id')))->toBe([1]);
        expect(column($handler->get('id')))->toBe([1, 2, 3, 4, 5]);
    });

    it('também limpa o estado quando a montagem falha', function () {
        $handler = DB::table('users');

        $bad = $handler->where('id', 1)->orderBy('id; DROP TABLE users')->get('id');

        expect(succeeded($bad))->toBeFalse();
        expect(column($handler->get('id')))->toBe([1, 2, 3, 4, 5]);
    });
});
