<?php

use App\Core\DB\Connection;

/*
 * A validação acontece ANTES de qualquer tentativa de conexão,
 * então estes testes não precisam de MySQL.
 */
function credentials(array $override = []): array
{
    return $override + [
        'db_host' => '127.0.0.1',
        'db_name' => 'app',
        'db_username' => 'user',
        'db_password' => 'pass',
        'db_charset' => 'utf8mb4',
    ];
}

describe('Connection::connect (validação de DSN)', function () {

    it('rejeita host inválido', function (string $host) {
        expect(fn() => Connection::instance()->connect(credentials(['db_host' => $host])))
            ->toThrow(InvalidArgumentException::class, 'host');
    })->with(['espaço' => ['bad host'], 'ponto e vírgula' => ['h;port=1'], 'vazio' => ['']]);

    it('rejeita nome de banco com tentativa de DSN injection', function (string $name) {
        expect(fn() => Connection::instance()->connect(credentials(['db_name' => $name])))
            ->toThrow(InvalidArgumentException::class, 'name');
    })->with([
        'troca de host' => ['app;host=servidor-malicioso'],
        'charset extra' => ['app;charset=latin1'],
        'espaço'        => ['app x'],
    ]);

    it('rejeita charset inválido', function () {
        expect(fn() => Connection::instance()->connect(credentials(['db_charset' => 'utf8;x'])))
            ->toThrow(InvalidArgumentException::class, 'charset');
    });
});
