<?php

use Wep\DB\SqlScript;
use Wep\Security\RateLimiter;
use Wep\Security\Token;
use Wep\Session;

describe('Token', function () {

    it('gera tokens aleatórios e senhas legíveis', function () {
        expect(Token::generate())->toMatch('/^[a-f0-9]{64}$/')
            ->and(Token::generate())->not->toBe(Token::generate())
            ->and(Token::password())->toMatch('/^[a-km-zA-HJ-NP-Z2-9]{12}$/');
    });

    it('o selo guarda só o hash e a expiração', function () {
        $token = Token::generate();
        $sealed = Token::seal($token, 3600);

        expect($sealed)->not->toContain($token)
            ->and(Token::parse($sealed)['expires_at'])->toBeGreaterThan(time())
            ->and(Token::verify($sealed, $token))->toBeTrue();
    });

    it('recusa token errado, expirado, vazio e selo em formato antigo (texto puro)', function () {
        $token = Token::generate();

        expect(Token::verify(Token::seal($token, 3600), 'outro'))->toBeFalse()
            ->and(Token::verify(Token::seal($token, -1), $token))->toBeFalse()
            ->and(Token::isExpired(Token::seal($token, -1)))->toBeTrue()
            ->and(Token::verify(Token::seal($token, 3600), ''))->toBeFalse()
            ->and(Token::verify($token, $token))->toBeFalse()
            ->and(Token::verify(null, $token))->toBeFalse();
    });

    it('lookup() é um padrão LIKE só com hex', function () {
        $token = Token::generate();

        expect(Token::lookup($token))->toMatch('/^[a-f0-9]{64}:%$/')
            ->and(str_starts_with(Token::seal($token, 60), rtrim(Token::lookup($token), '%')))->toBeTrue();
    });
});

describe('RateLimiter', function () {

    it('conta tentativas na janela e bloqueia ao atingir o máximo', function () {
        $limiter = new RateLimiter(tempDir('rate'));

        foreach (range(1, 4) as $n) {
            expect($limiter->hit('login:ana|1.2.3.4', 900))->toBe($n);
        }

        expect($limiter->tooManyAttempts('login:ana|1.2.3.4', 5))->toBeFalse();

        $limiter->hit('login:ana|1.2.3.4', 900);

        expect($limiter->tooManyAttempts('login:ana|1.2.3.4', 5))->toBeTrue()
            ->and($limiter->availableIn('login:ana|1.2.3.4'))->toBeGreaterThan(890)
            ->and($limiter->tooManyAttempts('login:bia|1.2.3.4', 5))->toBeFalse();
    });

    it('a janela expira e clear() zera', function () {
        $limiter = new RateLimiter(tempDir('rate'));

        $limiter->hit('k', -1); // janela já vencida
        expect($limiter->attempts('k'))->toBe(0);

        $limiter->hit('k', 900);
        $limiter->clear('k');
        expect($limiter->attempts('k'))->toBe(0);
    });

    it('chaves com caracteres especiais (username digitado) viram hash no arquivo', function () {
        $dir = tempDir('rate');
        $limiter = new RateLimiter($dir);

        $limiter->hit('login:../../etc/passwd*?|x', 900);

        expect(glob("$dir/*"))->toHaveCount(1)
            ->and(basename(glob("$dir/*")[0]))->toMatch('/^[a-f0-9]{64}\.json$/');
    });
});

describe('Session', function () {

    afterEach(fn () => Session::configure([]));

    it('cookie HttpOnly, SameSite=Lax e Secure só em HTTPS, por padrão', function () {
        expect(Session::cookieParams(false))->toMatchArray(['httponly' => true, 'samesite' => 'Lax', 'secure' => false, 'path' => '/', 'domain' => ''])
            ->and(Session::cookieParams(true)['secure'])->toBeTrue();
    });

    it('config: samesite, secure forçado e SameSite=None sem HTTPS cai para Lax', function () {
        Session::configure(['samesite' => 'strict', 'secure' => true, 'lifetime' => 3600]);
        expect(Session::cookieParams(false))->toMatchArray(['samesite' => 'Strict', 'secure' => true, 'lifetime' => 3600]);

        Session::configure(['samesite' => 'None']);
        expect(Session::cookieParams(false)['samesite'])->toBe('Lax')
            ->and(Session::cookieParams(true)['samesite'])->toBe('None');

        Session::configure(['samesite' => 'qualquer']);
        expect(Session::cookieParams(false)['samesite'])->toBe('Lax');
    });

    it('expiração por inatividade (padrão 2h)', function () {
        $now = 1_000_000;

        expect(Session::idleTimeout())->toBe(7200)
            ->and(Session::isIdleExpired($now - 7201, $now, 7200))->toBeTrue()
            ->and(Session::isIdleExpired($now - 7000, $now, 7200))->toBeFalse()
            ->and(Session::isIdleExpired(null, $now, 7200))->toBeFalse()
            ->and(Session::isIdleExpired($now - 99999, $now, 0))->toBeFalse();
    });
});

describe('SqlScript', function () {

    it('divide instruções respeitando strings e comentários', function () {
        $sql = <<<'SQL'
            -- tabela de menus
            CREATE TABLE `menu` (`id` int NOT NULL, `name` varchar(50) DEFAULT 'a;b'); /* comentário; com ; */
            INSERT INTO menu VALUES (1, "x;y"), (2, 'it''s; ok');
            # outro comentário;
            ALTER TABLE `menu` ADD PRIMARY KEY (`id`);
            COMMIT;
            SQL;

        expect(SqlScript::split($sql))->toBe([
            "CREATE TABLE `menu` (`id` int NOT NULL, `name` varchar(50) DEFAULT 'a;b')",
            "INSERT INTO menu VALUES (1, \"x;y\"), (2, 'it''s; ok')",
            'ALTER TABLE `menu` ADD PRIMARY KEY (`id`)',
        ]);
    });

    it('script vazio ou só comentários', function () {
        expect(SqlScript::split("-- nada\n/* nada */\n"))->toBe([]);
    });
});
