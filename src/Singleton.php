<?php

namespace Wep;

/**
 * Singleton: uma instância por classe.
 *
 * @example
 * class Connection extends Singleton {}
 * Connection::instance();
 */
class Singleton
{
    /** @var array<class-string, static> */
    private static array $instances = [];

    /**
     * Instância única da classe chamada (cada subclasse tem a sua).
     *
     * Antes era um "static $instance" dentro do método: a partir do PHP 8.1 subclasses que herdam
     * o método compartilham essa variável, então A::instance() e B::instance() devolviam o mesmo objeto.
     */
    public static function instance(): static
    {
        return self::$instances[static::class] ??= new static();
    }

    protected function __construct()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new \LogicException('Singleton não pode ser desserializado: ' . static::class);
    }
}
