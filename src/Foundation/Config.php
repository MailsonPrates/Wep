<?php

namespace Wep\Foundation;

/**
 * Acesso de leitura ao array de configuração (config/app.php).
 *
 * - "a.b.c" navega em arrays/objetos aninhados;
 * - "a|b" devolve "a" e, se não houver valor, tenta "b";
 * - "sem valor" é null ou "" (string vazia). false, 0 e "0" são valores válidos.
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        foreach (explode('|', $key) as $alternative) {
            $value = self::lookup($this->items, trim($alternative));

            if (!self::isMissing($value)) {
                return $value;
            }
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return !self::isMissing($this->get($key));
    }

    public static function isMissing(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function lookup(mixed $data, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        if (is_array($data) && array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_object($data) && property_exists($data, $segment)) {
                $data = $data->$segment;
            } else {
                return null;
            }
        }

        return $data;
    }
}
