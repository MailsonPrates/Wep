<?php

use Wep\Env\Env;
use Wep\Env\EnvCache;

afterEach(fn () => forgetEnv('WEP_T_A', 'WEP_T_B', 'WEP_T_C', 'WEP_T_BOOL', 'WEP_T_OFF', 'WEP_T_Q', 'WEP_T_X', 'WEP_T_NEW', 'WEP_T_EXT'));

describe('Env::parse / load', function () {

    it('lê pares NOME=valor, ignorando comentários, linhas vazias e linhas inválidas', function () {
        $env = new Env(tempEnv("# comentário\nWEP_T_A=1\n\nlinha sem igual\n=sem nome\n1INVALIDO=x\nWEP_T_B = dois \n"));

        expect($env->parse())->toBe(['WEP_T_A' => '1', 'WEP_T_B' => 'dois']);
    });

    it('aceita prefixo export, BOM e quebras de linha CRLF', function () {
        $env = new Env(tempEnv("\xEF\xBB\xBFexport WEP_T_A=x\r\nWEP_T_B=y\r\n"));

        expect($env->parse())->toBe(['WEP_T_A' => 'x', 'WEP_T_B' => 'y']);
    });

    it('mantém o "=" dentro do valor', function () {
        $env = new Env(tempEnv("WEP_T_A=a=b=c\n"));

        expect($env->parse()['WEP_T_A'])->toBe('a=b=c');
    });

    it('converte true/false em bool e remove aspas externas', function () {
        $env = new Env(tempEnv("WEP_T_BOOL=true\nWEP_T_OFF=FALSE\nWEP_T_Q=\"com espaço\"\nWEP_T_X='simples'\n"));

        expect($env->parse())->toBe([
            'WEP_T_BOOL' => true,
            'WEP_T_OFF' => false,
            'WEP_T_Q' => 'com espaço',
            'WEP_T_X' => 'simples',
        ]);
    });

    it('publica os valores em getenv, $_ENV e $_SERVER', function () {
        $env = new Env(tempEnv("WEP_T_A=valor\n"));
        $env->load();

        expect(getenv('WEP_T_A'))->toBe('valor')
            ->and($_ENV['WEP_T_A'])->toBe('valor')
            ->and($_SERVER['WEP_T_A'])->toBe('valor')
            ->and($env->get('WEP_T_A'))->toBe('valor');
    });

    it('não sobrescreve variáveis que já existem no ambiente real', function () {
        $_SERVER['WEP_T_EXT'] = 'do-servidor';

        $env = new Env(tempEnv("WEP_T_EXT=do-arquivo\n"));
        $env->load();

        expect($env->get('WEP_T_EXT'))->toBe('do-servidor')
            ->and($_SERVER['WEP_T_EXT'])->toBe('do-servidor');
    });

    it('lança exceção quando o arquivo não existe', function () {
        new Env(sys_get_temp_dir() . '/nao-existe-' . uniqid() . '.env');
    })->throws(InvalidArgumentException::class);
});

describe('Env::set', function () {

    it('atualiza a variável no arquivo e recarrega o valor', function () {
        $file = tempEnv("WEP_T_A=1\nWEP_T_B=2\n");
        $env = new Env($file);
        $env->load();

        expect($env->set('WEP_T_A', 'novo')->error)->toBeFalse()
            ->and(file_get_contents($file))->toBe("WEP_T_A=novo\nWEP_T_B=2\n")
            ->and($env->get('WEP_T_A'))->toBe('novo')
            ->and(getenv('WEP_T_A'))->toBe('novo');
    });

    it('preserva CRLF e linhas com export', function () {
        $file = tempEnv("# app\r\nexport WEP_T_A=1\r\nWEP_T_B=2");
        $env = new Env($file);

        $env->set('WEP_T_A', '9');

        expect(file_get_contents($file))->toBe("# app\r\nWEP_T_A=9\r\nWEP_T_B=2");
    });

    it('acrescenta a variável quando ela não existe (antes era ignorada em silêncio)', function () {
        $file = tempEnv("WEP_T_A=1\n");
        $env = new Env($file);

        $env->set('WEP_T_NEW', 'x');

        expect(file_get_contents($file))->toBe("WEP_T_A=1\nWEP_T_NEW=x\n")
            ->and($env->get('WEP_T_NEW'))->toBe('x');
    });

    it('não confunde variáveis com prefixo igual', function () {
        $file = tempEnv("WEP_T_AB=1\nWEP_T_A=2\n");
        $env = new Env($file);

        $env->set('WEP_T_A', '3');

        expect(file_get_contents($file))->toBe("WEP_T_AB=1\nWEP_T_A=3\n");
        forgetEnv('WEP_T_AB');
    });

    it('recusa quebra de linha no valor (injeção de variáveis no .env)', function () {
        $file = tempEnv("WEP_T_A=1\n");
        $env = new Env($file);

        $result = $env->set('WEP_T_A', "x\nDB_PASSWORD=hackeado");

        expect($result->error)->toBeTrue()
            ->and(file_get_contents($file))->toBe("WEP_T_A=1\n");
    });

    it('recusa nome de variável inválido', function () {
        $env = new Env(tempEnv("WEP_T_A=1\n"));

        expect($env->set('NOME=X', '1')->error)->toBeTrue()
            ->and($env->set('', '1')->error)->toBeTrue();
    });
});

describe('EnvCache', function () {

    it('grava e lê os valores como array PHP', function () {
        $envFile = tempEnv("WEP_T_A=1\n");
        touch($envFile, time() - 10);

        $cache = new EnvCache(dirname($envFile) . '/builds/env.build.php');

        expect($cache->write(['WEP_T_A' => '1', 'WEP_T_BOOL' => true]))->toBeTrue()
            ->and($cache->read($envFile))->toBe(['WEP_T_A' => '1', 'WEP_T_BOOL' => true]);
    });

    it('ignora o cache quando o .env é mais novo que ele', function () {
        $envFile = tempEnv("WEP_T_A=1\n");
        $cache = new EnvCache(dirname($envFile) . '/env.build.php');
        $cache->write(['WEP_T_A' => 'velho']);

        touch($cache->file(), time() - 10);
        touch($envFile, time());

        expect($cache->read($envFile))->toBeNull();
    });

    it('não escreve código PHP a partir dos valores (var_export)', function () {
        $envFile = tempEnv("X=1\n");
        touch($envFile, time() - 10);
        $cache = new EnvCache(dirname($envFile) . '/env.build.php');

        $cache->write(['WEP_T_X' => "'; echo 'pwned'; //"]);

        expect($cache->read($envFile))->toBe(['WEP_T_X' => "'; echo 'pwned'; //"]);
    });

    it('clear remove o arquivo', function () {
        $cache = new EnvCache(tempDir() . '/env.build.php');
        $cache->write(['A' => 1]);
        $cache->clear();

        expect(is_file($cache->file()))->toBeFalse();
    });
});
