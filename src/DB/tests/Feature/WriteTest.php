<?php

use App\Core\DB;

beforeEach(fn() => freshDatabase());
afterEach(fn() => DB::setConfig(['null_as_empty' => true, 'expose_errors' => false]));

describe('insert', function () {

    it('insere e devolve o último id', function () {
        $r = DB::table('users')->insert(['name' => 'Eva', 'email' => 'eva@x.com', 'age' => 33, 'stock' => 1, 'status' => 'on']);

        expect(succeeded($r))->toBeTrue();
        expect((int) meta($r, 'last_id'))->toBe(6);
        expect(userCount())->toBe(6);
    });

    it('create() é alias de insert()', function () {
        expect(succeeded(DB::table('users')->create(['name' => 'Eva'])))->toBeTrue();
        expect(userCount())->toBe(6);
    });

    it('aceita o formato verboso', function () {
        $r = DB::table('users')->insert(['fields' => ['name' => 'Eva', 'age' => 33]]);

        expect(succeeded($r))->toBeTrue();
        expect(column(DB::table('users')->where('name', 'Eva')->get('age'), 'age'))->toEqual([33]);
    });

    it('retorna erro com campos vazios', function () {
        $r = DB::table('users')->insert([]);

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toBe('Invalid params');
    });

    it('grava aspas e caracteres especiais sem alterar o valor', function () {
        $name = "D'Artagnan \"o\" \\ ; -- /* */";

        DB::table('users')->insert(['name' => $name]);

        expect(column(DB::table('users')->where('id', 6)->get('name'), 'name'))->toBe([$name]);
    });

    it('grava true como 1 e false como 0 (antes false virava "")', function () {
        DB::table('users')->insert(['name' => 'T', 'stock' => true]);
        DB::table('users')->insert(['name' => 'F', 'stock' => false]);

        expect(column(DB::table('users')->where('name', 'T')->get('stock'), 'stock'))->toBe([1]);
        expect(column(DB::table('users')->where('name', 'F')->get('stock'), 'stock'))->toBe([0]);
    });

    describe('NULL', function () {

        it('por padrão grava "" (comportamento legado, null_as_empty = true)', function () {
            DB::table('users')->insert(['name' => 'N', 'note' => null]);

            expect(column(DB::table('users')->where('name', 'N')->get('note'), 'note'))->toBe(['']);
        });

        it('grava NULL de verdade com null_as_empty = false', function () {
            DB::setConfig(['null_as_empty' => false]);
            DB::table('users')->insert(['name' => 'N', 'note' => null]);

            expect(column(DB::table('users')->where('name', 'N')->get('note'), 'note'))->toBe([null]);
        });
    });

    it('rejeita valor array', function () {
        $r = DB::table('users')->insert(['name' => ['a', 'b']]);

        expect(succeeded($r))->toBeFalse();
        expect(userCount())->toBe(5);
    });
});

describe('insert ... on duplicate key update (SQL gerado)', function () {

    // O SQLite não tem ON DUPLICATE KEY; aqui só conferimos a query montada.
    // A execução real está em tests/Integration.

    it('gera a cláusula com placeholders próprios', function () {
        $q = DB::table('items')->onDuplicateUpdate('qty', 'note')->debug()->insert(['sku' => 'A', 'qty' => 3, 'note' => 'x']);

        expect($q->string)->toBe(
            'INSERT INTO `items` (`sku`, `qty`, `note`) VALUES (:p1, :p2, :p3) '
            . 'ON DUPLICATE KEY UPDATE `qty` = :p4, `note` = :p5'
        );
        expect($q->placeholders)->toBe(['p1' => 'A', 'p2' => 3, 'p3' => 'x', 'p4' => 3, 'p5' => 'x']);
    });

    it('aceita as colunas em array', function () {
        $q = DB::table('items')->onDuplicateUpdate(['qty'])->debug()->insert(['sku' => 'A', 'qty' => 3]);

        expect($q->string)->toContain('ON DUPLICATE KEY UPDATE `qty` = :p3');
    });

    it('0 e "0" são valores válidos (regressão: antes eram descartados)', function () {
        $q = DB::table('items')->onDuplicateUpdate('qty')->debug()->insert(['sku' => 'A', 'qty' => 0]);

        expect($q->string)->toContain('ON DUPLICATE KEY UPDATE `qty` = :p3');
        expect($q->placeholders['p3'])->toBe(0);
    });

    it('null e "" não sobrescrevem o registro existente', function () {
        $q = DB::table('items')->onDuplicateUpdate('qty', 'note')->debug()->insert(['sku' => 'A', 'qty' => 4, 'note' => '']);

        expect($q->string)->toContain('ON DUPLICATE KEY UPDATE `qty` = :p4');
        expect($q->string)->not->toContain('`note` =');
    });

    it('omite a cláusula quando não há o que atualizar (antes gerava SQL inválido)', function () {
        $q = DB::table('items')->onDuplicateUpdate('note')->debug()->insert(['sku' => 'A', 'note' => '']);

        expect($q->string)->not->toContain('ON DUPLICATE');
    });

    it('ignora coluna maliciosa que não está nos campos do insert', function () {
        $q = DB::table('items')->onDuplicateUpdate('qty = 1; DROP TABLE items; --')->debug()->insert(['sku' => 'A']);

        expect($q->string)->not->toContain('DROP');
    });

    it('formato verboso com onDuplicateUpdate', function () {
        $q = DB::table('items')->debug()->insert(['fields' => ['sku' => 'A', 'qty' => 2], 'onDuplicateUpdate' => ['qty']]);

        expect($q->string)->toContain('ON DUPLICATE KEY UPDATE `qty` = :p3');
    });
});

describe('update', function () {

    it('atualiza uma coluna (chave, valor)', function () {
        $r = DB::table('users')->where('id', 2)->update('status', 'on');

        expect(succeeded($r))->toBeTrue();
        expect(userStatuses())->toBe(['on', 'on', 'on', 'on', 'off']);
    });

    it('atualiza várias colunas (array)', function () {
        DB::table('users')->where('id', 2)->update(['status' => 'x', 'stock' => 3]);

        expect(rows(DB::table('users')->where('id', 2)->get('status', 'stock')))->toEqual([['status' => 'x', 'stock' => 3]]);
    });

    it('aceita o formato verboso', function () {
        $r = DB::table('users')->update(['fields' => ['status' => 'x'], 'where' => ['id', 5]]);

        expect(succeeded($r))->toBeTrue();
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'x']);
    });

    it('informa quantas linhas foram afetadas', function () {
        $r = DB::table('users')->where('status', 'on')->update('stock', 1);

        expect((int) meta($r, 'affected_rows'))->toBe(3);
    });

    it('recusa update sem where e não altera nada', function () {
        $r = DB::table('users')->update('status', 'zzz');

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toContain('Insecure update');
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
    });

    it('recusa update só com or() (antes atualizava a tabela inteira)', function () {
        $r = DB::table('users')->or('id', 1)->update('status', 'HACK');

        expect(succeeded($r))->toBeFalse();
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
    });

    it('recusa update sem campos', function () {
        $r = DB::table('users')->where('id', 1)->update([]);

        expect(succeeded($r))->toBeFalse();
        expect(userStatuses())->toBe(['on', 'off', 'on', 'on', 'off']);
    });
});

describe('delete', function () {

    it('remove com where encadeado', function () {
        $r = DB::table('users')->where('id', 5)->delete();

        expect(succeeded($r))->toBeTrue();
        expect(userCount())->toBe(4);
    });

    it('remove passando chave e valor', function () {
        DB::table('users')->delete('id', 4);

        expect(userCount())->toBe(4);
    });

    it('remove passando array de condições', function () {
        DB::table('users')->delete(['status' => 'off']);   // Bruno e Davi

        expect(column(DB::table('users')->get('id')))->toBe([1, 3, 4]);
    });

    it('remove passando ["where" => ...]', function () {
        DB::table('users')->delete(['where' => ['id', 1]]);

        expect(column(DB::table('users')->get('id')))->toBe([2, 3, 4, 5]);
    });

    it('remove com ["where" => lista de condições] e ["where" => assoc]', function () {
        DB::table('users')->delete(['where' => [['status', 'off'], ['age', '>', 50]]]);   // Davi
        expect(column(DB::table('users')->get('id')))->toBe([1, 2, 3, 4]);

        DB::table('users')->delete(['where' => ['status' => 'off']]);                      // Bruno
        expect(column(DB::table('users')->get('id')))->toBe([1, 3, 4]);
    });

    it('recusa delete sem where', function () {
        $r = DB::table('users')->delete();

        expect(succeeded($r))->toBeFalse();
        expect(errorOf($r))->toContain('Insecure delete');
        expect(userCount())->toBe(5);
    });

    it('recusa delete só com or()', function () {
        $r = DB::table('users')->or('id', 1)->delete();

        expect(succeeded($r))->toBeFalse();
        expect(userCount())->toBe(5);
    });
});

describe('transações', function () {

    it('rollback desfaz as escritas', function () {
        DB::beginTransaction();
        DB::table('users')->insert(['name' => 'TX']);
        DB::rollback();

        expect(userCount())->toBe(5);
    });

    it('commit confirma as escritas', function () {
        DB::beginTransaction();
        DB::table('users')->insert(['name' => 'TX']);

        expect(DB::commit())->toBeTrue();
        expect(userCount())->toBe(6);
    });

    it('commit e rollback fora de transação são inofensivos', function () {
        expect(DB::commit())->toBeFalse();
        expect(DB::rollback())->toBeInstanceOf(DB::class);
    });
});
