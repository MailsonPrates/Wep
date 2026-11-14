<?php

use Wep\Foundation\Config;
use Wep\Foundation\Paths;
use Wep\Singleton;

describe('Config', function () {

    $config = new Config([
        'name' => 'Minha App',
        'title' => '',
        'dir' => ['root' => '/var/www/app', 'name' => null],
        'flags' => ['debug' => false, 'zero' => 0, 'string_zero' => '0'],
        'dotted.key' => 'literal',
    ]);

    it('lê chaves simples e aninhadas', function () use ($config) {
        expect($config->get('name'))->toBe('Minha App')
            ->and($config->get('dir.root'))->toBe('/var/www/app');
    });

    it('prefere a chave literal com ponto à navegação', function () use ($config) {
        expect($config->get('dotted.key'))->toBe('literal');
    });

    it('trata null e "" como sem valor (vale o fallback)', function () use ($config) {
        expect($config->get('title', 'fb'))->toBe('fb')
            ->and($config->get('dir.name', 'fb'))->toBe('fb')
            ->and($config->get('nao.existe', 'fb'))->toBe('fb');
    });

    it('respeita false, 0 e "0" (antes caíam no fallback)', function () use ($config) {
        expect($config->get('flags.debug', true))->toBeFalse()
            ->and($config->get('flags.zero', 10))->toBe(0)
            ->and($config->get('flags.string_zero', 'x'))->toBe('0');
    });

    it('"a|b" tenta a primeira chave e depois a segunda', function () use ($config) {
        expect($config->get('title|name'))->toBe('Minha App')
            ->and($config->get('name|title'))->toBe('Minha App')
            ->and($config->get('title|nada', 'fb'))->toBe('fb');
    });

    it('has()', function () use ($config) {
        expect($config->has('name'))->toBeTrue()
            ->and($config->has('title'))->toBeFalse()
            ->and($config->has('flags.debug'))->toBeTrue();
    });
});

describe('Paths', function () {

    it('monta os caminhos a partir da raiz', function () {
        $paths = new Paths('/var/www/app/');

        expect($paths->root())->toBe('/var/www/app')
            ->and($paths->config('.env'))->toBe('/var/www/app/config/.env')
            ->and($paths->assets('css', 'main.css'))->toBe('/var/www/app/public/assets/css/main.css')
            ->and($paths->builds('route-maps.php'))->toBe('/var/www/app/storage/builds/route-maps.php')
            ->and($paths->modules('User'))->toBe('/var/www/app/src/Modules/User');
    });

    it('normaliza barras nos segmentos', function () {
        $paths = new Paths('C:\\www\\app');

        expect($paths->root('/config/', '/.env'))->toBe('C:\\www\\app/config/.env')
            ->and($paths->root(''))->toBe('C:\\www\\app');
    });

    it('recusa raiz vazia', function () {
        new Paths('');
    })->throws(InvalidArgumentException::class);
});

class SingletonTestA extends Singleton {}
class SingletonTestB extends Singleton {}

describe('Singleton', function () {

    it('mantém uma instância por subclasse (bug do static compartilhado no PHP 8.1+)', function () {
        expect(SingletonTestA::instance())->toBe(SingletonTestA::instance())
            ->and(SingletonTestB::instance())->toBeInstanceOf(SingletonTestB::class)
            ->and(SingletonTestB::instance())->not->toBe(SingletonTestA::instance());
    });

    it('não pode ser instanciado com new', function () {
        new SingletonTestA();
    })->throws(Error::class);

    it('não pode ser desserializado', function () {
        unserialize(serialize(SingletonTestA::instance()));
    })->throws(LogicException::class);
});
